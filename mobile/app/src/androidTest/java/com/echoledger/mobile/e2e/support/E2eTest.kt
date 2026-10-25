package com.echoledger.mobile.e2e.support

import java.io.File
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.rules.TestRule
import org.junit.runner.Description
import org.junit.runners.model.Statement

/**
 * Puts the phone and the backend into a known state before each test and back afterwards: an unpaired app, a healthy
 * network and server, the phone unlocked and upright. When a test fails, a screenshot and the view hierarchy are saved
 * to the app's external media directory (`e2e-failures`) before the clean-up runs. Without the backend instrumentation
 * arguments (see [Backend]) the test is skipped before anything is touched.
 */
class E2eWorld : TestRule {
    override fun apply(base: Statement, description: Description): Statement = object : Statement() {
        override fun evaluate() {
            prepare()
            try {
                base.evaluate()
            } catch (e: Throwable) {
                saveFailureArtifacts(description)
                throw e
            } finally {
                restore()
            }
        }
    }

    private fun prepare() {
        Backend.assumeConfigured()
        val reachable = runCatching { Backend.counts() }
        assertTrue("test backend is not reachable at ${Backend.controlUrl}: ${reachable.exceptionOrNull()}", reachable.isSuccess)
        Backend.setFlakyMode("ok")
        Backend.resetStats()
        Shell.setNetwork(true)
        Shell.setNightMode(false)
        Shell.run("svc power stayon true")
        Ui.device.wakeUp()
        Shell.run("wm dismiss-keyguard")
        Ui.device.unfreezeRotation()
        Ui.device.setOrientationNatural()
        Ui.device.pressHome()
        AppState.reset()
    }

    private fun restore() {
        runCatching { Backend.setFlakyMode("ok") }
        Shell.setNetwork(true)
        Shell.setNightMode(false)
        runCatching { Ui.device.setOrientationNatural() }
        runCatching { Ui.device.unfreezeRotation() }
        Sharing.cleanUp()
        Ui.device.pressHome()
        AppState.reset()
    }

    private fun saveFailureArtifacts(description: Description) {
        val dir = Ui.artifactDir("e2e-failures")
        val name = "${description.className.substringAfterLast('.')}.${description.methodName}"
        runCatching { Ui.device.takeScreenshot(File(dir, "$name.png")) }
        runCatching { Ui.device.dumpWindowHierarchy(File(dir, "$name.xml")) }
    }
}

/** Base class of the end-to-end tests. */
abstract class E2eTest {
    @get:Rule
    val world = E2eWorld()

    /** Pairs the app with the server directly (token minted by the backend) and opens it on the Library. */
    protected fun launchPaired(viaFlaky: Boolean = false, name: String = "Registered phone"): Backend.Device {
        val device = Backend.registerDevice(viaFlaky, name)
        AppState.pairAs(device)
        Ui.launchApp()
        Ui.awaitText("Library", Ui.NORMAL)
        return device
    }
}
