package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.core.ReadyFacts
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.ui.v2.setup.HelperMethod
import com.echoledger.mobile.ui.v2.setup.SetupFacts

/** The phone's state at the moments that matter for Setup & health (a Galaxy S25 on Android 15). */
object SetupHealthFixtures {
    private fun facts(
        helper: Boolean = true,
        installed: Boolean = true,
        running: Boolean = true,
        allowed: Boolean = true,
        everConnected: Boolean = true,
        stale: Boolean = false,
        mic: Boolean = true,
        notifications: Boolean = true,
        battery: Boolean = true,
        callerNames: Boolean = true,
        autoRecord: Boolean = true,
        helperInBuild: Boolean = true,
        freeMb: Long = 48_000,
        developer: Boolean = true,
        wireless: Boolean = true,
        method: HelperMethod? = HelperMethod.Shizuku
    ) = SetupFacts(
        ReadyFacts(
            capabilities = Capabilities(35, helper, mic, notifications, overlayPermission = true, helperInBuild = helperInBuild),
            autoRecordOn = autoRecord,
            shizukuInstalled = installed,
            shizukuRunning = running,
            shizukuAllowed = allowed,
            helperEverConnected = everConnected,
            helperStale = stale,
            signedIn = true,
            freeStorageMb = freeMb,
            batteryExempt = battery,
            callerNamesAllowed = callerNames
        ),
        developerOptions = developer,
        wirelessDebugging = wireless,
        method = method
    )

    val allReady = facts()

    /** Never set up: Developer options off, no Shizuku. */
    val fresh =
        facts(
            helper = false,
            installed = false,
            running = false,
            allowed = false,
            everConnected = false,
            developer = false,
            wireless = false,
            autoRecord = false
        )

    /** Set up with Shizuku, then the phone restarted: Wireless debugging is off, so Shizuku and the helper are not running. */
    val afterRestart = facts(helper = false, running = false, allowed = false, wireless = false)

    /** The same after Wireless debugging was turned back on: only Start is left. */
    val afterRestartWirelessOn = facts(helper = false, running = false, allowed = false, wireless = true)

    /** Set up with the computer (ADB), then the phone restarted. */
    val computerAfterRestart =
        facts(helper = false, installed = false, running = false, allowed = false, wireless = false, method = HelperMethod.Computer)

    /** Shizuku was set up and used, then reinstalled (or its data cleared): installed again, not running. */
    val shizukuReinstalled = facts(helper = false, running = false, allowed = false, wireless = true)

    /** Shizuku installed but never used on this phone (no memory of it starting the helper), not running. */
    val shizukuNeverUsed = facts(helper = false, running = false, allowed = false, everConnected = false, wireless = true, method = null)

    /** The pairing was removed in Wireless debugging: Shizuku installed and stopped, Wireless debugging off. */
    val pairingRemoved = facts(helper = false, running = false, allowed = false, wireless = false)

    /** Developer options turned off again, though the helper ran before. */
    val developerOff = facts(helper = false, running = false, allowed = false, developer = false, wireless = false)

    /** Shizuku started but EchoLedger not allowed yet. */
    val notAllowed = facts(helper = false, allowed = false, everConnected = false, method = null)

    val staleHelper = facts(stale = true)

    val permissionsMissing = facts(mic = false, notifications = false, battery = false, callerNames = false, freeMb = 120)

    /** The Google Play build: no capture helper. */
    val playBuild = facts(
        helper = false,
        installed = false,
        running = false,
        allowed = false,
        everConnected = false,
        helperInBuild = false,
        method = null
    )
}
