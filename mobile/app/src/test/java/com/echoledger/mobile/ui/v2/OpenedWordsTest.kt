package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import android.content.res.Resources
import com.echoledger.mobile.R
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.ui.MainViewModel.OpenedRun
import com.echoledger.mobile.ui.classicOpenedWords
import com.echoledger.mobile.ui.v2.library.openedProblem
import com.echoledger.mobile.ui.v2.library.openedWords
import java.io.IOException
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config

/**
 * Review of 2.0.0-y: what Mark as read or unread says. "Check the connection" only when there was none; a refusal in
 * the server's own words; an older server's 405 as needing a newer server; the Urdu toasts in the approved wording.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class OpenedWordsTest {
    private val en: Resources = RuntimeEnvironment.getApplication().resources
    private val ur: Resources = RuntimeEnvironment.getApplication()
        .createConfigurationContext(Configuration(en.configuration).apply { setLocale(Locale("ur")) }).resources

    /** 3 of 10 done, then [failure]. */
    private fun stopped(failure: Throwable) = OpenedRun(
        done = setOf("a", "b", "c"),
        notFound = emptySet(),
        left = listOf("d", "e", "f", "g", "h", "i", "j"),
        failure = failure
    )

    private val offline = IOException("Unable to resolve host \"echoledger.test\"")
    private val refused = ApiException(403, "You can't change these recordings.", "forbidden")
    private val down = ApiException(503, "Service Unavailable", "x")
    private val older = ApiException(405, "The DELETE method is not supported.", "method_not_allowed")

    @Test
    fun onlyNoConnectionSaysToCheckTheConnection() {
        assertEquals(
            "Stopped: 3 of 10 marked. Can't reach the server. Check the connection and try again.",
            openedWords(en, stopped(offline), read = true)
        )
        assertEquals("Stopped: 3 of 10 marked. You can't change these recordings.", openedWords(en, stopped(refused), read = true))
        val serverDown = "Stopped: 3 of 10 marked. The server had a problem. Try again in a minute."
        assertEquals(serverDown, openedWords(en, stopped(down), read = true))
        for (failure in listOf(refused, down, older)) {
            assertFalse("${failure.status}", openedWords(en, stopped(failure), read = false).contains("connection"))
            assertFalse("${failure.status}", classicOpenedWords(stopped(failure), read = false).contains("connection"))
        }
    }

    @Test
    fun anOlderServers405NeedsANewerServer() {
        assertEquals(
            "Stopped: 3 of 10 marked. Mark as unread needs a newer EchoLedger server. Ask the person who runs it to update.",
            openedWords(en, stopped(older), read = false)
        )
        // Nothing done: only the problem.
        val none = OpenedRun(emptySet(), emptySet(), listOf("a"), older)
        val unread = "Mark as unread needs a newer EchoLedger server. Ask the person who runs it to update."
        assertEquals(unread, openedWords(en, none, read = false))
        assertEquals(
            "Mark as read needs a newer EchoLedger server. Ask the person who runs it to update.",
            openedProblem(en, older, read = true)
        )
        assertEquals(unread, classicOpenedWords(none, read = false))
        assertEquals(
            "Stopped: 3 of 10 marked as read. Can't reach the server. Check the connection and try again.",
            classicOpenedWords(stopped(offline), read = true)
        )
    }

    @Test
    fun theUrduToastsUseTheApprovedWording() {
        val one = OpenedRun(setOf("a"), emptySet(), emptyList())
        val two = OpenedRun(setOf("a", "b"), emptySet(), emptyList())
        assertEquals("1 ریکارڈنگ پڑھی ہوئی نشان زد ہو گئی", openedWords(ur, one, read = true))
        assertEquals("2 ریکارڈنگز نہ پڑھی گئی نشان زد ہو گئیں", openedWords(ur, two, read = false))
        assertEquals("پڑھی ہوئی نشان زد ہو گئی", ur.getString(R.string.v2_lib_marked_read_one))
        assertEquals("نہ پڑھی گئی نشان زد ہو گئی", ur.getString(R.string.v2_lib_marked_unread_one))
        // The problem in Urdu too: never the English sentence inside the Urdu one.
        val words = openedWords(ur, stopped(offline), read = true)
        assertEquals("رک گیا: 10 میں سے 3 ہو گئیں۔ " + ur.getString(R.string.v2_vm_offline_check), words)
        assertFalse(words, words.contains("connection"))
    }
}
