package com.echoledger.mobile.calls

import java.io.File
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Package visibility for the Phone app's own recordings (v1.8 0.3, review): since Android 11 the media store names the
 * app that made a file, and Android says whether it came with the phone, only for apps the website build may see.
 */
class PhoneAppVisibilityTest {
    private val manifest: String by lazy {
        listOf("src/full/AndroidManifest.xml", "app/src/full/AndroidManifest.xml").map(::File).first { it.isFile }.readText()
    }

    @Test
    fun `the website build may see every Phone app that owns call recordings, and the Phone app's call settings`() {
        PhoneAppRecordings.OWNERS.forEach { pkg ->
            assertTrue("$pkg is not in the full manifest's queries", manifest.contains("<package android:name=\"$pkg\" />"))
        }
        assertTrue(manifest.contains("<action android:name=\"android.telecom.action.SHOW_CALL_SETTINGS\" />"))
    }
}
