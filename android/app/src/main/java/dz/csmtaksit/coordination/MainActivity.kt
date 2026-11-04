package dz.csmtaksit.coordination

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.view.View
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.ProgressBar
import android.widget.Toast

/**
 * Application Coordination : une seule vue web, limitée à l'application Coordination
 * du Nextcloud de l'entreprise. La connexion Nextcloud est autorisée ; toute autre page
 * Nextcloud ramène à Coordination ; les sites externes s'ouvrent dans le navigateur.
 */
class MainActivity : Activity() {

    private lateinit var web: WebView
    private lateinit var progress: ProgressBar
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var pendingPermission: PermissionRequest? = null
    private val openAfterDownload = mutableSetOf<Long>()

    private val server: Uri = Uri.parse(BuildConfig.SERVER)
    private val home: String = BuildConfig.SERVER + "/index.php/apps/coordination/"

    /** Pages du serveur que l'application peut afficher : Coordination et la connexion. */
    private val allowedPrefixes = listOf(
        "/index.php/apps/coordination", "/apps/coordination",
        "/index.php/login", "/login", "/index.php/logout", "/logout",
    )

    companion object {
        private const val REQ_FILE = 10
        private const val REQ_CAMERA = 11
        private const val UA_SUFFIX = " CoordinationAndroid/1"
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        web = WebView(this)
        progress = ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal).apply {
            isIndeterminate = true
            visibility = View.GONE
        }
        val root = FrameLayout(this)
        root.addView(web, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        root.addView(progress, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, (6 * resources.displayMetrics.density).toInt()))
        setContentView(root)

        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false)

        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false // flux caméra du scanner
            allowFileAccess = false
            allowContentAccess = true
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            setSupportMultipleWindows(false) // les liens « nouvel onglet » restent dans l'application
            userAgentString = userAgentString + UA_SUFFIX
        }

        web.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                if (!request.isForMainFrame) return false
                return route(request.url)
            }

            override fun onPageStarted(view: WebView, url: String?, favicon: Bitmap?) {
                progress.visibility = View.VISIBLE
            }

            override fun onPageFinished(view: WebView, url: String?) {
                progress.visibility = View.GONE
                CookieManager.getInstance().flush()
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (request.isForMainFrame) showOffline(error.description?.toString() ?: "")
            }
        }

        web.webChromeClient = object : WebChromeClient() {
            /** Caméra du scanner en direct : accordée après l'autorisation Android. */
            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread {
                    val wantsCamera = request.resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)
                    if (!wantsCamera || !isOurServer(request.origin)) { request.deny(); return@runOnUiThread }
                    if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                        request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
                    } else {
                        pendingPermission = request
                        requestPermissions(arrayOf(Manifest.permission.CAMERA), REQ_CAMERA)
                    }
                }
            }

            /** Champs « fichier » (galerie, PDF, appareil photo de secours). */
            override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = callback
                return try {
                    startActivityForResult(params.createIntent(), REQ_FILE)
                    true
                } catch (e: ActivityNotFoundException) {
                    fileCallback = null
                    Toast.makeText(this@MainActivity, "Aucune application pour choisir un fichier", Toast.LENGTH_LONG).show()
                    false
                }
            }

            override fun onProgressChanged(view: WebView, newProgress: Int) {
                progress.visibility = if (newProgress < 100) View.VISIBLE else View.GONE
            }
        }

        // Téléchargements (scans PDF) : gestionnaire Android avec la session, puis ouverture.
        web.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            download(url, userAgent, contentDisposition, mimeType, open = true)
        }

        registerDownloadReceiver()

        if (savedInstanceState != null) web.restoreState(savedInstanceState) else web.loadUrl(intentUrl(intent) ?: home)
    }

    private fun isOurServer(uri: Uri?): Boolean =
        uri != null && uri.scheme == "https" && uri.host.equals(server.host, ignoreCase = true) && (uri.port == server.port)

    /** true = navigation interceptée par l'application. */
    private fun route(uri: Uri): Boolean {
        val scheme = uri.scheme?.lowercase()
        if (scheme == "tel" || scheme == "mailto" || scheme == "geo" || scheme == "sms") {
            openExternal(uri); return true
        }
        if (scheme != "https" && scheme != "http") return true // file:, intent:, javascript: … refusés
        if (!isOurServer(uri)) { openExternal(uri); return true }

        val path = uri.path ?: "/"
        // Scan PDF : ouvert dans le lecteur PDF du téléphone (la vue web n'affiche pas les PDF).
        if (path.contains("/apps/coordination/api/dossiers/") && path.endsWith("/file")) {
            download(uri.toString(), web.settings.userAgentString, null, "application/pdf", open = true)
            return true
        }
        if (allowedPrefixes.any { path.startsWith(it) }) return false
        // Toute autre page Nextcloud (fichiers, tableau de bord…) : retour à Coordination.
        web.loadUrl(home)
        return true
    }

    private fun intentUrl(intent: Intent?): String? {
        val data = intent?.data ?: return null
        return if (isOurServer(data)) data.toString() else null
    }

    private fun openExternal(uri: Uri) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, "Aucune application pour ouvrir ce lien", Toast.LENGTH_SHORT).show()
        }
    }

    private fun download(url: String, userAgent: String?, contentDisposition: String?, mimeType: String?, open: Boolean) {
        val uri = Uri.parse(url)
        if (!isOurServer(uri)) { openExternal(uri); return }
        val name = URLUtil.guessFileName(url, contentDisposition, mimeType ?: "application/pdf")
            .let { if (it.endsWith(".bin")) it.removeSuffix(".bin") + ".pdf" else it }
        val request = DownloadManager.Request(uri)
            .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
            .addRequestHeader("User-Agent", userAgent ?: web.settings.userAgentString)
            .setMimeType(mimeType ?: "application/pdf")
            .setTitle(name)
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .apply {
                // Android 10+ : dossier Téléchargements public sans permission ; avant : dossier de l'application.
                if (Build.VERSION.SDK_INT >= 29) setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "Coordination/$name")
                else setDestinationInExternalFilesDir(this@MainActivity, Environment.DIRECTORY_DOWNLOADS, name)
            }
        val id = (getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
        if (open) openAfterDownload.add(id)
        Toast.makeText(this, "Téléchargement : $name", Toast.LENGTH_SHORT).show()
    }

    private val downloadReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1)
            if (!openAfterDownload.remove(id)) return
            val dm = getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
            val file = dm.getUriForDownloadedFile(id)
            if (file == null) {
                Toast.makeText(context, "Téléchargement échoué : vérifiez la connexion", Toast.LENGTH_LONG).show(); return
            }
            try {
                startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(file, "application/pdf").addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
            } catch (e: ActivityNotFoundException) {
                Toast.makeText(context, "PDF enregistré dans Téléchargements/Coordination", Toast.LENGTH_LONG).show()
            }
        }
    }

    private fun registerDownloadReceiver() {
        val filter = IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE)
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(downloadReceiver, filter, Context.RECEIVER_EXPORTED)
        else @Suppress("UnspecifiedRegisterReceiverFlag") registerReceiver(downloadReceiver, filter)
    }

    private fun showOffline(reason: String) {
        val html = """
            <!doctype html><html lang="fr"><meta name="viewport" content="width=device-width,initial-scale=1">
            <body style="font-family:sans-serif;padding:32px 20px;text-align:center;color:#1e293b">
            <div style="font-size:48px">📡</div><h2>Connexion impossible</h2>
            <p>Vérifiez le réseau (4G ou Wi-Fi), puis réessayez.</p>
            <p style="color:#64748b;font-size:13px">${android.text.TextUtils.htmlEncode(reason)}</p>
            <a href="$home" style="display:inline-block;margin-top:16px;padding:14px 28px;border-radius:12px;background:#0f766e;color:#fff;text-decoration:none;font-size:17px">Réessayer</a>
            </body></html>
        """.trimIndent()
        web.loadDataWithBaseURL(home, html, "text/html", "utf-8", home)
    }

    @Deprecated("API Activity")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        if (requestCode == REQ_FILE) {
            fileCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data))
            fileCallback = null
            return
        }
        @Suppress("DEPRECATION")
        super.onActivityResult(requestCode, resultCode, data)
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        if (requestCode == REQ_CAMERA) {
            val request = pendingPermission ?: return
            pendingPermission = null
            if (grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED) request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
            else {
                request.deny()
                Toast.makeText(this, "Caméra refusée : le scanner utilisera l'appareil photo du téléphone", Toast.LENGTH_LONG).show()
            }
        }
    }

    @Deprecated("API Activity")
    override fun onBackPressed() {
        if (web.canGoBack()) web.goBack() else @Suppress("DEPRECATION") super.onBackPressed()
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        intentUrl(intent)?.let { web.loadUrl(it) }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
        web.onPause()
    }

    override fun onResume() {
        super.onResume()
        web.onResume()
    }

    override fun onDestroy() {
        unregisterReceiver(downloadReceiver)
        web.destroy()
        super.onDestroy()
    }
}
