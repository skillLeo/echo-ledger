package com.echoledger.mobile.ui.v2

import android.app.Application
import com.echoledger.mobile.data.BinItemDto
import com.echoledger.mobile.ui.v2.library.trashKeepHours
import com.echoledger.mobile.ui.v2.shell.VmMessages
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The v2 words: English and Urdu resources in step, Trash's real length, and the view model's messages in Urdu. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = Application::class)
class V2WordsTest {
    private val resDir = File("src/main/res")

    private fun strings(folder: String): Map<String, String> = resDir.resolve(folder).listFiles { f -> f.name.startsWith("strings_v2") }!!
        .flatMap { f ->
            Regex("""<string name="([^"]+)"[^>]*>(.*?)</string>""").findAll(f.readText()).map {
                it.groupValues[1] to
                    it.groupValues[2]
            }
        }
        .toMap()

    private fun args(s: String) = Regex("""%\d\$[ds]""").findAll(s).map { it.value }.toSortedSet()

    @Test
    fun englishAndUrduHaveTheSameKeysAndArguments() {
        val en = strings("values")
        val ur = strings("values-ur")
        assertTrue(en.size > 100)
        assertEquals(en.keys, ur.keys)
        for ((key, text) in en) assertEquals(key, args(text), args(ur.getValue(key)))
    }

    @Test
    fun trashSaysTheServersRealLengthNever30Days() {
        val en = V2Testing.text(EchoLanguage.English)
        assertEquals("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.", en.trashKeeps(48))
        assertEquals(
            "حذف کی ہوئی ریکارڈنگز یہاں 48 گھنٹے رہتی ہیں، پھر ان کی آڈیو اور ٹرانسکرپٹ مٹا دیے جاتے ہیں۔",
            V2Testing.text(EchoLanguage.Urdu).trashKeeps(48)
        )
        val everything = (strings("values").values + strings("values-ur").values).joinToString("\n") +
            File("src/main/java/com/echoledger/mobile/ui/v2").walkTopDown().filter {
                it.extension == "kt"
            }.joinToString("\n") { it.readText() }
        assertFalse(Regex("""30\s*(days|دن)""").containsMatchIn(everything))
    }

    @Test
    fun trashLengthComesFromTheConfigThenTheServersDatesThen48() {
        val bin = listOf(BinItemDto("01JX", "Karachi Cargo Services", deletedAt = "2026-11-17T05:00:00Z", eraseAt = "2026-11-20T05:00:00Z"))
        assertEquals(24, trashKeepHours(24, bin))
        assertEquals(72, trashKeepHours(null, bin))
        assertEquals(48, trashKeepHours(null, emptyList()))
        assertEquals(48, trashKeepHours(null, listOf(BinItemDto("01JY", "Voice note"))))
    }

    @Test
    fun everyMappedViewModelSentenceIsOneItReallySays() {
        val source = File("src/main/java/com/echoledger/mobile/ui/MainViewModel.kt").readText()
        val missing = VmMessages.known.filterNot { "\"$it\"" in source }
        assertTrue("no longer in MainViewModel: $missing", missing.isEmpty())
    }

    @Test
    fun theViewModelsMessagesReadInUrdu() {
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        assertEquals("واپس آ گئی", VmMessages.localize(ur, "Restored"))
        assertEquals("ayesha@bilaltraders.pk کے طور پر سائن ان ہو گیا", VmMessages.localize(ur, "Signed in as ayesha@bilaltraders.pk"))
        assertEquals("3 لوگوں کو بھیج دی گئی", VmMessages.localize(ur, "Sent to 3 people"))
        // A server's own words have no key and show as they are.
        assertEquals(
            "Monthly limit reached — raise it in Settings",
            VmMessages.localize(ur, "Monthly limit reached — raise it in Settings")
        )
        assertEquals("Restored", VmMessages.localize(V2Testing.resources(EchoLanguage.English), "Restored"))
    }
}
