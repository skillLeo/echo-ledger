package com.echoledger.mobile.testsupport

import android.app.Activity
import android.content.Intent
import androidx.test.core.app.ApplicationProvider
import androidx.work.testing.WorkManagerTestInitHelper
import com.echoledger.mobile.EchoLedgerApp
import java.util.Locale
import java.util.TimeZone
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Before
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.android.controller.ActivityController
import org.robolectric.annotation.Config

/**
 * Base class for tests that need the real application graph: Keystore-backed ciphers are swapped for in-memory ones,
 * persisted state is wiped, WorkManager runs in test mode (work is queued but never starts by itself) and a scripted
 * EchoLedger server is available on localhost.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
abstract class AppTest {
    protected lateinit var app: EchoLedgerApp
    protected lateinit var server: FakeServer
    private lateinit var savedZone: TimeZone
    private lateinit var savedLocale: Locale
    private val activities = mutableListOf<ActivityController<*>>()

    @Before
    fun prepareApp() {
        savedZone = TimeZone.getDefault()
        savedLocale = Locale.getDefault()
        TimeZone.setDefault(TimeZone.getTimeZone("UTC"))
        Locale.setDefault(Locale.US)
        forgetCachedViewModelFactory()
        forgetSettingsDataStore()
        app = ApplicationProvider.getApplicationContext()
        app.useTestCiphers()
        WorkManagerTestInitHelper.initializeTestWorkManager(app)
        runBlocking { app.resetPersistedState() }
        server = FakeServer()
    }

    /**
     * AndroidViewModelFactory keeps the first Application it was given in a static field, which would hand every later
     * test's activity the previous test's application graph.
     */
    private fun forgetCachedViewModelFactory() {
        val factory = Class.forName("androidx.lifecycle.ViewModelProvider\$AndroidViewModelFactory")
        factory.getDeclaredField("_instance").apply { isAccessible = true }.set(null, null)
    }

    /** Builds an activity that is torn down after the test, so its Compose root cannot leak into the next one. */
    protected fun <T : Activity> buildActivity(type: Class<T>, intent: Intent? = null): ActivityController<T> {
        val controller = if (intent == null) Robolectric.buildActivity(type) else Robolectric.buildActivity(type, intent)
        activities += controller
        return controller
    }

    @After
    fun releaseApp() {
        activities.asReversed().forEach { runCatching { it.pause().stop().destroy() } }
        activities.clear()
        closeSettingsDataStore()
        server.close()
        TimeZone.setDefault(savedZone)
        Locale.setDefault(savedLocale)
    }
}
