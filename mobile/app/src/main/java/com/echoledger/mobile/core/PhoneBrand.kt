package com.echoledger.mobile.core

/**
 * Phone makers whose Android stops background apps in its own way, with the exact steps and settings screens for each
 * (since v1.5, plan Phase 1B). Paths are the ones the maker's current system shows; a screen that does not open on an
 * older system falls back to the app's own settings page, and the steps still say where to go.
 */
enum class PhoneBrand(val label: String) {
    SAMSUNG("Samsung"),
    XIAOMI("Xiaomi, Redmi or POCO"),
    OPPO("Oppo or Realme"),
    ONEPLUS("OnePlus"),
    VIVO("Vivo or iQOO"),
    HUAWEI("Huawei or Honor"),
    PIXEL("Pixel"),
    OTHER("this phone");

    /** A settings screen of the phone maker's own, tried in order. */
    data class Screen(val pkg: String, val cls: String)

    /** What keeps EchoLedger running in the background on this brand. */
    val backgroundSteps: List<String>
        get() = when (this) {
            SAMSUNG -> listOf(
                "Settings → Battery → Background usage limits → Never auto sleeping apps → add EchoLedger.",
                "Settings → Apps → EchoLedger → Battery → Unrestricted."
            )

            XIAOMI -> listOf(
                "Settings → Apps → Manage apps → EchoLedger → Autostart: on.",
                "On the same page: Battery saver → No restrictions.",
                "Open Recents, hold EchoLedger's card and tap the lock, so clearing Recents leaves it running."
            )

            OPPO -> listOf(
                "Settings → Apps → App management → EchoLedger → Battery usage → Allow background activity and Allow auto launch.",
                "Open Recents, tap the menu on EchoLedger's card and choose Lock."
            )

            ONEPLUS -> listOf(
                "Settings → Apps → App management → EchoLedger → Battery usage → Allow background activity and Allow auto launch.",
                "Settings → Battery → Battery optimisation → EchoLedger → Don't optimise."
            )

            VIVO -> listOf(
                "Settings → Battery → Background power consumption management → EchoLedger → Don't restrict.",
                "Settings → Apps → Autostart → EchoLedger: on."
            )

            HUAWEI -> listOf(
                "Settings → Battery → App launch → EchoLedger: turn Manage automatically off.",
                "In the box that opens, leave Auto-launch, Secondary launch and Run in background on."
            )

            PIXEL -> listOf("Settings → Apps → EchoLedger → App battery usage → Unrestricted.")

            OTHER -> listOf("Settings → Apps → EchoLedger → Battery → Unrestricted (or Don't optimise).")
        }

    /** The maker's autostart or battery screen, when it has one; the first that opens is used. */
    val backgroundScreens: List<Screen>
        get() = when (this) {
            SAMSUNG -> listOf(Screen("com.samsung.android.lool", "com.samsung.android.sm.battery.ui.BatteryActivity"))

            XIAOMI -> listOf(Screen("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"))

            OPPO -> listOf(
                Screen("com.coloros.safecenter", "com.coloros.safecenter.permission.startup.StartupAppListActivity"),
                Screen("com.coloros.safecenter", "com.coloros.safecenter.startupapp.StartupAppListActivity"),
                Screen("com.oppo.safe", "com.oppo.safe.permission.startup.StartupAppListActivity")
            )

            ONEPLUS -> listOf(Screen("com.oneplus.security", "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity"))

            VIVO -> listOf(
                Screen("com.vivo.permissionmanager", "com.vivo.permissionmanager.activity.BgStartUpManagerActivity"),
                Screen("com.iqoo.secure", "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity")
            )

            HUAWEI -> listOf(
                Screen("com.huawei.systemmanager", "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity"),
                Screen("com.hihonor.systemmanager", "com.hihonor.systemmanager.startupmgr.ui.StartupNormalAppListActivity")
            )

            PIXEL, OTHER -> emptyList()
        }

    /** How to reach Developer options and Wireless debugging, which the capture helper needs. */
    val developerSteps: List<String>
        get() {
            val (unlock, open) = when (this) {
                SAMSUNG -> "Settings → About phone → Software information → tap Build number 7 times." to "Settings → Developer options"

                XIAOMI ->
                    "Settings → About phone → tap OS version (or MIUI version) 7 times." to
                        "Settings → Additional settings → Developer options"

                OPPO ->
                    "Settings → About device → Version → tap Build number 7 times." to
                        "Settings → Additional settings → Developer options"

                ONEPLUS -> "Settings → About device → Version → tap Build number 7 times." to "Settings → System → Developer options"

                VIVO -> "Settings → About phone → Software version: tap it 7 times." to "Settings → System management → Developer options"

                HUAWEI -> "Settings → About phone → tap Build number 7 times." to "Settings → System & updates → Developer options"

                PIXEL -> "Settings → About phone → tap Build number 7 times." to "Settings → System → Developer options"

                OTHER -> "Settings → About phone → tap Build number 7 times." to "Settings → Developer options (often under System)"
            }
            val extra = if (this ==
                XIAOMI
            ) {
                listOf("Also turn on USB debugging (Security settings): Xiaomi phones need it for the helper.")
            } else {
                emptyList()
            }
            return listOf(unlock, "$open → Wireless debugging: on.") + extra
        }

    companion object {
        /** From Build.MANUFACTURER and Build.BRAND; sub-brands share their maker's system. */
        fun of(manufacturer: String?, brand: String?): PhoneBrand {
            val names = listOfNotNull(manufacturer, brand).map { it.trim().lowercase() }
            fun any(vararg words: String) = names.any { name -> words.any { name == it || name.startsWith(it) } }
            return when {
                any("samsung") -> SAMSUNG
                any("xiaomi", "redmi", "poco") -> XIAOMI
                any("oneplus") -> ONEPLUS
                any("oppo", "realme") -> OPPO
                any("vivo", "iqoo") -> VIVO
                any("huawei", "honor") -> HUAWEI
                any("google") -> PIXEL
                else -> OTHER
            }
        }
    }
}
