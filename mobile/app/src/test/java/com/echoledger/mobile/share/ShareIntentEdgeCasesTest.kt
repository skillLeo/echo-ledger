package com.echoledger.mobile.share

import android.content.Intent
import android.net.Uri
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** What the share-sheet parser accepts and rejects. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ShareIntentEdgeCasesTest {
    private fun send(type: String?, vararg uris: String, action: String = Intent.ACTION_SEND): Intent {
        val intent = Intent(action)
        type?.let { intent.setType(it) }
        if (action == Intent.ACTION_SEND_MULTIPLE) {
            intent.putParcelableArrayListExtra(Intent.EXTRA_STREAM, ArrayList(uris.map { Uri.parse(it) }))
        } else if (uris.isNotEmpty()) {
            intent.putExtra(Intent.EXTRA_STREAM, Uri.parse(uris.first()))
        }
        return intent
    }

    @Test
    fun `every audio family and the Ogg containers are accepted case-insensitively`() {
        listOf(
            "audio/ogg", "audio/opus", "audio/mpeg", "audio/mp4", "audio/x-m4a", "audio/aac", "audio/amr",
            "audio/wav", "audio/webm", "audio/flac", "AUDIO/OGG", "Audio/Mpeg", "application/ogg", "APPLICATION/OGG"
        ).forEach {
            assertTrue(it, ShareIntentParser.isAcceptedMime(it))
        }
    }

    @Test
    fun `media types with parameters are still audio`() {
        assertTrue(ShareIntentParser.isAcceptedMime("audio/ogg; codecs=opus"))
        assertEquals("audio/ogg; codecs=opus", ShareIntentParser.parse(send("audio/ogg; codecs=opus", "content://a/1")).single().mime)
    }

    @Test
    fun `other media families are rejected`() {
        listOf(
            "image/png",
            "video/mp4",
            "text/plain",
            "application/pdf",
            "application/json",
            "application/zip",
            "*/*",
            "audio",
            "",
            "  "
        ).forEach {
            assertFalse("[$it]", ShareIntentParser.isAcceptedMime(it))
        }
        assertFalse(ShareIntentParser.isAcceptedMime(null))
    }

    @Test
    fun `an untyped intent yields nothing`() {
        assertTrue(ShareIntentParser.parse(send(null, "content://a/1")).isEmpty())
    }

    @Test
    fun `only content uris are accepted for sharing`() {
        assertEquals(1, ShareIntentParser.parse(send("audio/ogg", "content://media/external/audio/1")).size)
        listOf(
            "https://example.com/a.ogg",
            "http://example.com/a.ogg",
            "ftp://example.com/a.ogg",
            "android.resource://pkg/1",
            "intent://x",
            "javascript:alert(1)",
            "/sdcard/a.ogg",
            "a.ogg"
        ).forEach {
            assertTrue("should drop $it", ShareIntentParser.parse(send("audio/ogg", it)).isEmpty())
        }
    }

    @Test
    fun `a missing or empty attachment yields nothing`() {
        assertTrue(ShareIntentParser.parse(send("audio/ogg")).isEmpty())
        assertTrue(ShareIntentParser.parse(send("audio/ogg", action = Intent.ACTION_SEND_MULTIPLE)).isEmpty())
    }

    @Test
    fun `multiple shares keep order and drop only the unusable entries`() {
        val shared = ShareIntentParser.parse(
            send(
                "audio/ogg",
                "content://a/1",
                "https://example.com/x",
                "content://a/2",
                "content://a/3",
                action = Intent.ACTION_SEND_MULTIPLE
            )
        )
        assertEquals(listOf("content://a/1", "content://a/2", "content://a/3"), shared.map { it.uri.toString() })
        assertTrue(shared.all { it.mime == "audio/ogg" })
    }

    @Test
    fun `other actions are ignored even with audio attached`() {
        listOf(Intent.ACTION_VIEW, Intent.ACTION_EDIT, Intent.ACTION_MAIN, "com.example.CUSTOM").forEach {
            assertTrue(it, ShareIntentParser.parse(send("audio/ogg", "content://a/1", action = it)).isEmpty())
        }
    }

    @Test
    fun `the stored media type is lower case`() {
        assertEquals("audio/ogg", ShareIntentParser.parse(send("AUDIO/OGG", "content://a/1")).single().mime)
    }

    @Test
    fun `unicode and very long content uris are carried through`() {
        val long = "content://provider/" + "a".repeat(5_000)
        assertEquals(long, ShareIntentParser.parse(send("audio/ogg", long)).single().uri.toString())
        val unicode = ShareIntentParser.parse(send("audio/ogg", "content://provider/%E4%BD%A0%E5%A5%BD.opus")).single()
        assertEquals("content", unicode.uri.scheme)
    }

    @Test
    fun `file uris are not accepted because they bypass the sender's access grant`() {
        assertTrue(
            ShareIntentParser.parse(
                send("audio/ogg", "file:///data/user/0/com.echoledger.mobile/files/datastore/echoledger_settings.preferences_pb")
            ).isEmpty()
        )
        assertTrue(ShareIntentParser.parse(send("audio/ogg", "file:///sdcard/Download/note.opus")).isEmpty())
    }
}
