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

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ShareIntentParserTest {
    @Test
    fun `accepted mimes cover audio and ogg containers only`() {
        assertTrue(ShareIntentParser.isAcceptedMime("audio/ogg"))
        assertTrue(ShareIntentParser.isAcceptedMime("AUDIO/MP4"))
        assertTrue(ShareIntentParser.isAcceptedMime("application/ogg"))
        assertFalse(ShareIntentParser.isAcceptedMime("image/png"))
        assertFalse(ShareIntentParser.isAcceptedMime(null))
    }

    @Test
    fun `a single shared voice note is parsed`() {
        val intent = Intent(Intent.ACTION_SEND)
            .setType("audio/ogg")
            .putExtra(Intent.EXTRA_STREAM, Uri.parse("content://media/audio/42"))
        val shared = ShareIntentParser.parse(intent)
        assertEquals(1, shared.size)
        assertEquals("audio/ogg", shared.first().mime)
        assertEquals("content", shared.first().uri.scheme)
    }

    @Test
    fun `multiple shared notes are parsed and non-content schemes are dropped`() {
        val intent = Intent(Intent.ACTION_SEND_MULTIPLE)
            .setType("audio/mpeg")
            .putParcelableArrayListExtra(
                Intent.EXTRA_STREAM,
                arrayListOf(Uri.parse("content://a/1"), Uri.parse("https://example.com/x.mp3"))
            )
        val shared = ShareIntentParser.parse(intent)
        assertEquals(1, shared.size)
        assertEquals("content://a/1", shared.first().uri.toString())
    }

    @Test
    fun `an image share and a null intent yield nothing`() {
        val image = Intent(Intent.ACTION_SEND).setType("image/png").putExtra(Intent.EXTRA_STREAM, Uri.parse("content://a/1"))
        assertTrue(ShareIntentParser.parse(image).isEmpty())
        assertTrue(ShareIntentParser.parse(null).isEmpty())
    }
}
