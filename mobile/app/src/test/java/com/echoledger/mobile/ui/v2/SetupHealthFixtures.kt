package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.core.ReadyFacts
import com.echoledger.mobile.privilege.Capabilities
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
        wireless: Boolean = true
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
        wirelessDebugging = wireless
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

    /** Set up before, then the phone restarted: Wireless debugging is off, so Shizuku and the helper are not running. */
    val afterRestart = facts(helper = false, running = false, allowed = false, wireless = false)

    /** Shizuku started but EchoLedger not allowed yet. */
    val notAllowed = facts(helper = false, allowed = false, everConnected = false)

    val staleHelper = facts(stale = true)

    val permissionsMissing = facts(mic = false, notifications = false, battery = false, callerNames = false, freeMb = 120)

    /** The Google Play build: no capture helper. */
    val playBuild = facts(
        helper = false,
        installed = false,
        running = false,
        allowed = false,
        everConnected = false,
        helperInBuild = false
    )
}
