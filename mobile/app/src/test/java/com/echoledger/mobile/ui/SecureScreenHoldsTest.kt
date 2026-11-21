package com.echoledger.mobile.ui

import android.view.WindowManager
import androidx.activity.ComponentActivity
import com.echoledger.mobile.testsupport.AppTest
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * FLAG_SECURE on the app's window: the app lock's "hide content" choice and the secret screens (the two-step key, the
 * recovery codes) each hold it, and neither clears it while the other still wants it.
 */
class SecureScreenHoldsTest : AppTest() {
    private fun secure(activity: ComponentActivity) = activity.window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE != 0

    @Test
    fun `a secret screen closing leaves the app lock's flag on`() {
        val activity = buildActivity(ComponentActivity::class.java).setup().get()
        activity.window.applySecureScreen(true)
        val release = activity.window.holdSecure()
        assertTrue(secure(activity))
        release()
        assertTrue(secure(activity))
        activity.window.applySecureScreen(false)
        assertFalse(secure(activity))
    }

    @Test
    fun `the app lock's option turned off leaves a secret screen covered until it closes`() {
        val activity = buildActivity(ComponentActivity::class.java).setup().get()
        assertFalse(secure(activity))
        val first = activity.window.holdSecure()
        val second = activity.window.holdSecure()
        activity.window.applySecureScreen(false)
        assertTrue(secure(activity))
        first()
        first()
        assertTrue(secure(activity))
        second()
        assertFalse(secure(activity))
    }
}
