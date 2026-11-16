package com.echoledger.mobile.ui.v2

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The v2 interface takes every colour, size, text size and corner radius from the design tokens
 * (docs/v2/DESIGN_SYSTEM.md): outside ui/v2/tokens/ no source may write a colour literal, a dp or sp value, or a corner
 * radius as a number, and outside ui/v2/theme/ no named colour (Color.White), Dp(…), em or TextUnit(…) either. It also
 * refuses looping progress indicators and infinite transitions (only the recording dot loops, in the theme's pulse).
 */
class TokensOnlyTest {
    private val rules = listOf(
        Regex("""Color\(0x""") to "colour literal",
        Regex("""\d(\.\d+)?f?\s*\.dp\b|\.dp\b""") to "dp value",
        Regex("""\.sp\b""") to "sp value",
        Regex("""RoundedCornerShape\(\s*[\d.]""") to "numeric corner radius",
        Regex("""CircularProgressIndicator\(|LinearProgressIndicator\(|infiniteRepeatable|rememberInfiniteTransition""") to
            "looping animation"
    )

    /** Allowed in ui/v2/theme/ only, where the roles and the type scale are built. */
    private val themeOnly = listOf(
        Regex("""\bColor\.[A-Z][A-Za-z]+""") to "named colour",
        Regex("""(?<![A-Za-z])Dp\(""") to "Dp value",
        Regex("""\.em\b""") to "em value",
        Regex("""TextUnit\(""") to "TextUnit value"
    )

    /** The lines of [source] that break a rule, as "line: what". */
    private fun findings(source: String, inTheme: Boolean = false): List<String> = source.lines().flatMapIndexed { i, line ->
        val code = line.substringBefore("//").trimStart()
        if (code.startsWith("*") || code.startsWith("/*") || code.startsWith("import ")) return@flatMapIndexed emptyList()
        (if (inTheme) rules else rules + themeOnly).filter { (re, _) -> re.containsMatchIn(code) }.map { (_, what) -> "${i + 1}: $what" }
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
            val pulse = rememberInfiniteTransition()
            Text("a", color = Color.White)
            Box(Modifier.background(Color.Black))
            Modifier.size(Dp(12f))
            TextStyle(letterSpacing = 0.5.em)
            TextStyle(lineHeight = TextUnit(20f, TextUnitType.Sp))
            Modifier.padding(EchoTokens.Space.s16)
            Box(Modifier.background(EchoV2.colors.clear))
            // a comment may say 48 dp or Color(0x
        """.trimIndent()
        assertEquals(12, findings(planted).map { it.substringBefore(':') }.distinct().size)
        // The theme may build roles and type from named colours and units, never from literals.
        assertEquals(listOf("1: colour literal"), findings("val a = Color(0xFF6750A4)\nval clear = Color.Transparent", inTheme = true))
    }

    @Test
    fun v2SourcesUseTokensOnly() {
        val files = roots.filter { it.isDirectory }.flatMap { root ->
            root.walkTopDown().filter {
                it.isFile && it.extension == "kt" && "tokens" !in it.relativeTo(root).path.split(File.separatorChar)
            }.toList()
        }
        assertTrue("no v2 sources found from ${File(".").absolutePath}", files.size > 10)
        val problems = files.flatMap { f ->
            findings(f.readText(), inTheme = "theme" in f.path.split(File.separatorChar)).map { "${f.path}:$it" }
        }
        assertTrue(problems.joinToString("\n"), problems.isEmpty())
    }
}
