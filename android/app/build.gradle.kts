plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val server = (project.findProperty("coordinationServer") as String?) ?: "https://cloud.csmtaksit.dz"
val keystorePath = System.getenv("COORD_KEYSTORE")

android {
    namespace = "dz.csmtaksit.coordination"
    compileSdk = 34

    defaultConfig {
        applicationId = "dz.csmtaksit.coordination"
        minSdk = 24
        targetSdk = 34
        versionCode = (System.getenv("COORD_VERSION_CODE") ?: "1").toInt()
        versionName = "1.0." + (System.getenv("COORD_VERSION_CODE") ?: "1")
        buildConfigField("String", "SERVER", "\"$server\"")
        manifestPlaceholders["serverHost"] = server.removePrefix("https://").removePrefix("http://").substringBefore('/')
    }

    signingConfigs {
        if (keystorePath != null) {
            create("release") {
                storeFile = file(keystorePath)
                storePassword = System.getenv("COORD_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("COORD_KEY_ALIAS")
                keyPassword = System.getenv("COORD_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = if (keystorePath != null) signingConfigs.getByName("release") else signingConfigs.getByName("debug")
        }
    }

    buildFeatures { buildConfig = true }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
}
