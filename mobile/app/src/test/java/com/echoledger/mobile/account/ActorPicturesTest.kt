package com.echoledger.mobile.account

import java.io.File
import java.nio.file.Files
import java.util.concurrent.atomic.AtomicInteger
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.delay
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Lead decision 27 on the phone: one fetch per server, person and version for every row that shows them; no picture is
 * remembered (no refetch on every redraw) until a new signed address or a while later; the disk copy is whole or none.
 */
class ActorPicturesTest {
    private val dir: File = Files.createTempDirectory("avatars").toFile()
    private val cache = AvatarCache(dir, "https://ledger.test")
    private var clock = 1_000_000L
    private val fetches = AtomicInteger()

    /** Pictures as text: "bad" doesn't draw. */
    private fun shared() = SharedPictures(decode = { b: ByteArray -> String(b).takeIf { it != "bad" } }, missMs = 60_000, now = { clock })

    private fun fetchGiving(answer: String?): suspend (String) -> ByteArray? = {
        fetches.incrementAndGet()
        delay(50)
        answer?.toByteArray()
    }

    @Test
    fun thirtyRowsOfOnePersonAskOnce() = runBlocking {
        val pictures = shared()
        val answers = (1..30).map { async { pictures.picture(cache, "https://ledger.test", "7", 3, "u1", fetchGiving("face")) } }.awaitAll()
        assertEquals(1, fetches.get())
        assertTrue(answers.all { it == "face" })
        // Kept on disk too: another run of the app draws it without a fetch.
        assertEquals("face", String(cache.get("7", 3)!!))
        assertEquals("face", shared().picture(cache, "https://ledger.test", "7", 3, "u1", fetchGiving("other")))
        assertEquals(1, fetches.get())
    }

    @Test
    fun noPictureIsRememberedUntilANewAddressOrAWhileLater() = runBlocking {
        val pictures = shared()
        assertNull(pictures.picture(cache, "https://ledger.test", "8", 1, "u1", fetchGiving(null)))
        assertNull(pictures.picture(cache, "https://ledger.test", "8", 1, "u1", fetchGiving("face")))
        assertEquals("redraws ask no more", 1, fetches.get())
        // A new signed address (the next hour's) asks again.
        assertEquals("face", pictures.picture(cache, "https://ledger.test", "8", 1, "u2", fetchGiving("face")))
        assertEquals(2, fetches.get())
        // And so does the same address once the while has passed.
        assertNull(pictures.picture(cache, "https://ledger.test", "9", 1, "u1", fetchGiving(null)))
        clock += 60_001
        assertEquals("face", pictures.picture(cache, "https://ledger.test", "9", 1, "u1", fetchGiving("face")))
    }

    @Test
    fun aNewVersionIsAnotherPictureAndAFetchThatFailsShowsTheInitials() = runBlocking {
        val pictures = shared()
        assertEquals("v1", pictures.picture(cache, "https://ledger.test", "7", 1, "u1", fetchGiving("v1")))
        assertEquals("v2", pictures.picture(cache, "https://ledger.test", "7", 2, "u1", fetchGiving("v2")))
        assertNull(cache.get("7", 1))
        val failing: suspend (String) -> ByteArray? = { error("offline") }
        assertNull(pictures.picture(cache, "https://ledger.test", "10", 1, "u1", failing))
    }

    @Test
    fun aKeptCopyThatDoesntDrawIsForgottenAndAskedForAgain() = runBlocking {
        cache.put("7", 4, "bad".toByteArray())
        assertEquals("face", shared().picture(cache, "https://ledger.test", "7", 4, "u1", fetchGiving("face")))
        assertEquals(1, fetches.get())
        assertEquals("face", String(cache.get("7", 4)!!))
        // Fetched bytes that don't draw are not kept.
        assertNull(shared().picture(cache, "https://ledger.test", "11", 1, "u1", fetchGiving("bad")))
        assertNull(cache.get("11", 1))
    }

    @Test
    fun anotherServersPersonSevenIsAnotherPicture() = runBlocking {
        val pictures = shared()
        assertEquals("ours", pictures.picture(cache, "https://ledger.test", "7", 1, "u1", fetchGiving("ours")))
        val theirs = AvatarCache(dir, "https://other.test")
        assertEquals("theirs", pictures.picture(theirs, "https://other.test", "7", 1, "u1", fetchGiving("theirs")))
        assertEquals(2, fetches.get())
    }

    @Test
    fun clearForgetsWhatIsHeldInMemory() = runBlocking {
        val pictures = shared()
        assertNull(pictures.picture(cache, "https://ledger.test", "12", 1, "u1", fetchGiving(null)))
        pictures.clear()
        assertEquals("face", pictures.picture(cache, "https://ledger.test", "12", 1, "u1", fetchGiving("face")))
    }

    @Test
    fun aCopyIsWrittenWholeAndOnlyTheNewestVersionStays() {
        cache.put("7", 1, "one".toByteArray())
        cache.put("7", 2, "two".toByteArray())
        assertNull(cache.get("7", 1))
        assertEquals("two", String(cache.get("7", 2)!!))
        assertEquals("no draft left behind", emptyList<String>(), dir.list()!!.filter { it.endsWith(".tmp") })
        assertEquals(1, dir.list()!!.size)
    }

    // The review of the phone's 2.0.0-aa: a disk that fails, a sign-out during a load, a row coming back into view.

    @Test
    fun aDiskThatFailsStillDrawsThePictureAndNeverFails() = runBlocking {
        // A "folder" that is a file: every write to it throws (a full disk, a folder gone at sign-out).
        val notAFolder = File.createTempFile("not-a-folder", ".bin")
        val broken = AvatarCache(notAFolder, "https://ledger.test")
        val pictures = shared()
        assertEquals("face", pictures.picture(broken, "https://ledger.test", "7", 1, "u1", fetchGiving("face")))
        // Drawn, so the next rows draw it at once, with no second fetch.
        assertEquals("face", pictures.picture(broken, "https://ledger.test", "7", 1, "u1", fetchGiving("other")))
        assertEquals(1, fetches.get())
    }

    @Test
    fun picturesThatCantBeDecodedAreNoPictureNeverAnError() = runBlocking {
        val throwing = SharedPictures(decode = { _: ByteArray -> error("can't decode") }, now = { clock })
        assertNull(throwing.picture(cache, "https://ledger.test", "7", 1, "u1", fetchGiving("face")))
    }

    @Test
    fun aSignOutDuringALoadKeepsNothing() = runBlocking {
        val pictures = shared()
        val slow: suspend (String) -> ByteArray? = {
            delay(300)
            "face".toByteArray()
        }
        val running = async { pictures.picture(cache, "https://ledger.test", "7", 5, "u1", slow) }
        delay(50)
        pictures.clear()
        // The row that asked still draws it, but nothing comes back to the disk or into memory.
        assertEquals("face", running.await())
        assertNull(cache.get("7", 5))
        assertNull(pictures.peek("7", 5))
    }

    @Test
    fun aDrawnPictureIsThereAtOnceForTheNextRow() = runBlocking {
        val pictures = shared()
        assertNull(pictures.peek("7", 6))
        assertEquals("face", pictures.picture(cache, "https://ledger.test", "7", 6, "u1", fetchGiving("face")))
        assertEquals("face", pictures.peek("7", 6))
        assertNull("another version is another picture", pictures.peek("7", 7))
        pictures.clear()
        assertNull(pictures.peek("7", 6))
    }
}
