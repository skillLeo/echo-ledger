package com.echoledger.mobile

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The copy rules of the design system (C9): sentence case, no emoji, the "…" character instead of three dots, and
 * no message that only says something failed ("Oops", "Something went wrong", "An error occurred"). Read from the sources so
 * new screens follow them too.
 */
class CopyRulesTest {
    private val sources: List<File> = listOf("src/main/java", "app/src/main/java")
        .map(::File)
        .firstOrNull { it.isDirectory }
        ?.walkTopDown()
        ?.filter { it.isFile && it.extension == "kt" }
        ?.toList()
        .orEmpty()

    /** Every string literal on lines that are not comments. */
    private fun literals(): List<Pair<String, String>> = sources.flatMap { file ->
        file.readLines().filterNot { it.trimStart().startsWith("//") || it.trimStart().startsWith("*") }
            .flatMap { line -> Regex("\"([^\"\\\\]|\\\\.)*\"").findAll(line).map { file.name to it.value }.toList() }
    }

    @Test
    fun `the sources are found`() {
        assertTrue("no Kotlin sources found from ${File(".").absolutePath}", sources.size > 50)
    }

    @Test
    fun `no emoji in text`() {
        val emoji = Regex("[\\x{1F300}-\\x{1FAFF}\\x{2600}-\\x{26FF}\\x{2700}-\\x{27BF}]")
        assertEquals(emptyList<Pair<String, String>>(), literals().filter { emoji.containsMatchIn(it.second) })
    }

    @Test
    fun `three dots are written as an ellipsis`() {
        assertEquals(emptyList<Pair<String, String>>(), literals().filter { it.second.contains("...") && it.second.any(Char::isLetter) })
    }

    @Test
    fun `no message only says that something failed`() {
        val vague = Regex("^\"(oops|something went wrong|an error occurred|unknown error)[.!]?\"$", RegexOption.IGNORE_CASE)
        assertEquals(emptyList<Pair<String, String>>(), literals().filter { vague.matches(it.second) })
    }
}
