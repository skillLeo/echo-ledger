package com.echoledger.mobile.translate

import android.content.Context
import android.content.Intent
import android.content.pm.ApplicationInfo
import com.echoledger.mobile.core.CallTargets

/** An app on the home screen that Live Translate can listen to. */
data class InstalledApp(val packageName: String, val label: String, val game: Boolean)

object TranslateApps {
    /** Games whose voice chat is the reason Game translate exists; shown first even if the store calls them something else. */
    val KNOWN_GAMES: Map<String, String> = mapOf(
        "com.tencent.ig" to "PUBG Mobile",
        "com.pubg.imobile" to "BGMI",
        "com.pubg.krmobile" to "PUBG Mobile KR",
        "com.vng.pubgmobile" to "PUBG Mobile VN",
        "com.activision.callofduty.shooter" to "Call of Duty: Mobile",
        "com.garena.game.codm" to "Call of Duty: Mobile (Garena)",
        "com.dts.freefireth" to "Free Fire",
        "com.dts.freefiremax" to "Free Fire MAX"
    )

    /** Video apps listed first in Watch translate. */
    val KNOWN_VIDEO: Map<String, String> = mapOf(
        "com.google.android.youtube" to "YouTube",
        "com.android.chrome" to "Chrome",
        "org.videolan.vlc" to "VLC",
        "com.mxtech.videoplayer.ad" to "MX Player",
        "com.netflix.mediaclient" to "Netflix",
        "com.amazon.avod.thirdpartyclient" to "Prime Video"
    )

    /**
     * Games first (known ones, then what Android calls a game), then the rest by name; never this app or a call app
     * (a game's voice chat must never start the call recorder, and a call app is never translated).
     */
    fun forPicker(apps: List<InstalledApp>, own: String, gamesFirst: Boolean): List<InstalledApp> {
        val usable = apps.filter { it.packageName != own && it.packageName !in CallTargets.KNOWN.keys }.distinctBy { it.packageName }
        val first = if (gamesFirst) KNOWN_GAMES else KNOWN_VIDEO
        return usable.sortedWith(
            compareBy<InstalledApp>(
                { it.packageName !in first },
                { !(gamesFirst && it.game) },
                { it.label.lowercase() }
            )
        )
    }

    /** The apps with a home-screen icon. */
    fun installed(context: Context): List<InstalledApp> {
        val pm = context.packageManager
        val launcher = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER)
        return runCatching { pm.queryIntentActivities(launcher, 0) }.getOrDefault(emptyList()).mapNotNull { info ->
            val app = info.activityInfo?.applicationInfo ?: return@mapNotNull null
            val pkg = app.packageName
            InstalledApp(
                packageName = pkg,
                label = KNOWN_GAMES[pkg] ?: runCatching { info.loadLabel(pm).toString() }.getOrDefault(pkg),
                game = pkg in KNOWN_GAMES || app.category == ApplicationInfo.CATEGORY_GAME
            )
        }
    }

    /** The name to show for a package that may no longer be installed. */
    fun label(context: Context, pkg: String): String = KNOWN_GAMES[pkg] ?: KNOWN_VIDEO[pkg] ?: runCatching {
        val pm = context.packageManager
        pm.getApplicationLabel(pm.getApplicationInfo(pkg, 0)).toString()
    }.getOrDefault(pkg)
}
