package com.echoledger.mobile.ui.v2

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The v2 interface takes every colour, size, text size and corner radius from the design tokens
 * (docs/v2/DESIGN_SYSTEM.md): outside ui/v2/tokens/ no source may write a colour literal, a dp or sp value, or a corner
 * radius as a number. It also refuses a looping progress indicator (only the recording dot loops).
 */
class TokensOnlyTest {
    private val rules = listOf(
        Regex("""Color\(0x""") to "colour literal",
        Regex("""\d(\.\d+)?f?\s*\.dp\b|\.dp\b""") to "dp value",
        Regex("""\.sp\b""") to "sp value",
        Regex("""RoundedCornerShape\(\s*[\d.]""") to "numeric corner radius",
        Regex("""CircularProgressIndicator\(|LinearProgressIndicator\(|infiniteRepeatable""") to "looping animation"
    )

    /** The lines of [source] that break a rule, as "line: what". */
    private fun findings(source: String): List<String> = source.lines().flatMapIndexed { i, line ->
        val code = line.substringBefore("//").trimStart()
        if (code.startsWith("*") || code.startsWith("/*") || code.startsWith("import ")) return@flatMapIndexed emptyList()
        rules.filter { (re, _) -> re.containsMatchIn(code) }.map { (_, what) -> "${i + 1}: $what" }
    }

    private val roots = listOf("src/main/java", "src/debug/java").map { File(it, "com/echoledger/mobile/ui/v2") }

    @Test
    fun theCheckCatchesPlantedValues() {
        val planted = """
            val a = Color(0xFF6750A4)
            Modifier.padding(12.dp)
            TextStyle(fontSize = 15.sp)
            RoundedCornerShape(20.dp)
            RoundedCornerShape(6)
            CircularProgressIndicator()
            Modifier.padding(EchoTokens.Space.s16)
            // a comment may say 48 dp or Color(0x
        """.trimIndent()
        assertEquals(6, findings(planted).map { it.substringBefore(':') }.distinct().size)
    }

    @Test
    fun v2SourcesUseTokensOnly() {
        val files = roots.filter { it.isDirectory }.flatMap { root ->
            root.walkTopDown().filter {
                it.isFile && it.extension == "kt" && "tokens" !in it.relativeTo(root).path.split(File.separatorChar)
            }.toList()
        }
        assertTrue("no v2 sources found from ${File(".").absolutePath}", files.size > 10)
        val problems = files.flatMap { f -> findings(f.readText()).map { "${f.path}:$it" } }
        assertTrue(problems.joinToString("\n"), problems.isEmpty())
    }
}
