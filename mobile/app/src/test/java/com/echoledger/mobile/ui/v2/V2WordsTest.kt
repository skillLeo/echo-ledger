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

    /**
     * The Kotlin string literals in [text], as written (templates kept), skipping the strings inside `${…}` and the
     * ones only compared with (`== "delete"`).
     */
    private fun literals(text: String): List<String> {
        val out = mutableListOf<String>()
        var i = 0
        while (i < text.length) {
            if (text[i] != '"') {
                i++
                continue
            }
            val sb = StringBuilder()
            val start = i
            i++
            var depth = 0
            while (i < text.length) {
                val ch = text[i]
                when {
                    depth == 0 && ch == '\\' && i + 1 < text.length -> {
                        sb.append(text[i + 1])
                        i += 2
                        continue
                    }

                    depth == 0 && ch == '"' -> break

                    ch == '$' && i + 1 < text.length && text[i + 1] == '{' -> {
                        depth++
                        sb.append("\${")
                        i += 2
                        continue
                    }

                    depth > 0 && ch == '}' -> depth--
                }
                sb.append(ch)
                i++
            }
            val before = text.substring(0, start).trimEnd()
            if (!before.endsWith("==") && !before.endsWith("!=")) out += sb.toString()
            i++
        }
        return out
    }

    /**
     * Every sentence MainViewModel puts in its message: the literals of `_message.value =`, `say(`, `showMessage(` and
     * `workspaceCall(` (its failure sentence),
     * with the statement's continuation lines (an if/else over several lines).
     */
    private fun viewModelSentences(): Set<String> {
        val lines = File("src/main/java/com/echoledger/mobile/ui/MainViewModel.kt").readLines()
        val marker = Regex("""_message\.value\s*=|\bsay\(|showMessage\(|workspaceCall\(""")
        fun indent(s: String) = s.length - s.trimStart().length
        return lines.indices.flatMap { i ->
            val m = marker.find(lines[i]) ?: return@flatMap emptyList()
            val text = StringBuilder(lines[i].substring(m.range.first))
            var j = i + 1
            while (j < lines.size && lines[j].isNotBlank() && indent(lines[j]) > indent(lines[i])) text.append('\n').append(lines[j++])
            literals(text.toString())
        }.filter { it.any(Char::isLetter) }.toSet()
    }

    @Test
    fun everySentenceTheViewModelSaysHasAKeyOrIsListedAsEnglishOnly() {
        val sentences = viewModelSentences()
        assertTrue("found only ${sentences.size}", sentences.size > 30)
        val templates = VmMessages.templates.map { it.source }.toSet()
        val missing = sentences.filterNot { it in VmMessages.known || it in templates || it in VmMessages.englishOnly }
        assertTrue("no key and not English-only: $missing", missing.isEmpty())
    }

    /** The sentences of core.Problems, the one mapper from a failure to words, which the view model says through it. */
    private fun problemsSentences(): Set<String> = com.echoledger.mobile.core.Problems::class.java.declaredFields
        .filter { java.lang.reflect.Modifier.isStatic(it.modifiers) && it.type == String::class.java }
        .map { field ->
            field.isAccessible = true
            field.get(null) as String
        }.toSet()

    @Test
    fun everyMappedSentenceAndTemplateIsOneTheViewModelReallySays() {
        val sentences = viewModelSentences() + problemsSentences()
        val gone = VmMessages.known.filterNot { it in sentences } + VmMessages.templates.map { it.source }.filterNot { it in sentences }
        assertTrue("no longer in MainViewModel: $gone", gone.isEmpty())
        // Each template filled in reads back through its own pattern.
        for (t in VmMessages.templates) {
            val sample = t.sample ?: t.source.replace(Regex("""\$\{[^}]*\}|\$[a-z]+"""), "3")
            assertTrue("${t.source} -> $sample", t.pattern.matches(sample))
        }
    }

    @Test
    fun theViewModelsMessagesReadInUrdu() {
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        assertEquals("واپس آ گئی", VmMessages.localize(ur, "Restored"))
        assertEquals("ayesha@bilaltraders.pk کے طور پر سائن ان ہو گیا", VmMessages.localize(ur, "Signed in as ayesha@bilaltraders.pk"))
        assertEquals("3 لوگوں کو بھیج دی گئی", VmMessages.localize(ur, "Sent to 3 people"))
        assertEquals("\u2066echoledger.pk\u2069 پر سائن ان ہو گیا", VmMessages.localize(ur, "Signed in to echoledger.pk"))
        // A server's text set between the sentence's own marks can't close them early or leave one open.
        assertEquals("\u2066x\u202Ey\u2069 پر سائن ان ہو گیا", VmMessages.localize(ur, "Signed in to x\u2069\u202Ey"))
        assertEquals("\u2066Ali\u2068evil\u2069\u2069 پر سائن ان ہو گیا", VmMessages.localize(ur, "Signed in to Ali\u2068evil"))
        // A server's own words have no key and show as they are.
        assertEquals(
            "Monthly limit reached — raise it in Settings",
            VmMessages.localize(ur, "Monthly limit reached — raise it in Settings")
        )
        assertEquals("Restored", VmMessages.localize(V2Testing.resources(EchoLanguage.English), "Restored"))
    }

    /**
     * EL-UX's audit: the sentences of core.Problems (can't reach, the server's problem, the certificate, sign in first)
     * come from that mapper, not from a literal in the view model, so the check above never saw them; they showed in
     * English on Urdu screens. Each one has a key and reads in Urdu.
     */
    @Test
    fun everySentenceOfProblemsHasAKeyAndReadsInUrdu() {
        val sentences = problemsSentences()
        assertTrue("found only ${sentences.size}", sentences.size >= 4)
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        for (english in sentences) {
            assertTrue("no key: $english", english in VmMessages.known)
            val urdu = VmMessages.localize(ur, english)
            assertTrue("not in Urdu: $english -> $urdu", urdu != english && urdu.any { it in '\u0600'..'\u06FF' })
        }
    }
}
