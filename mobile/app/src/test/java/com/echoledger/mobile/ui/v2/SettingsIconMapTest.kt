package com.echoledger.mobile.ui.v2

import androidx.compose.ui.graphics.vector.ImageVector
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.settings.SettingsGoal
import com.echoledger.mobile.ui.v2.settings.SettingsTree
import java.io.File
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The phone's Settings icons follow the one map of every platform (D3, tokens/icons/settings.json): each group and page
 * has the map's glyph; one meaning, one glyph (no two groups share one, no two pages of a group share one, a group's
 * glyph differs from its pages' unless it has one page); and only Sign out and Help mirror in right to left.
 */
class SettingsIconMapTest {
    private val map: JsonObject = Json.parseToJsonElement(File("../../tokens/icons/settings.json").readText()).jsonObject
    private val groups = map.getValue("groups").jsonObject
    private val pages = map.getValue("pages").jsonObject

    /** The phone's page ids as the map names them. */
    private fun mapId(page: String) = when (page) {
        "language" -> "transcription.language"
        "account" -> "profile"
        SettingsTree.SETUP -> "setup_health"
        else -> page
    }

    /** The name an ImageVector built from the map's Material entry has. */
    private fun expected(entry: JsonObject): String {
        val material = entry.getValue("material").jsonPrimitive.content
        val mirrored = entry["mirrored"]?.jsonPrimitive?.content == "AutoMirrored"
        return if (mirrored) "AutoMirrored.Outlined.$material" else "Outlined.$material"
    }

    private val goalIcons: Map<SettingsGoal, ImageVector> = SettingsGoal.entries.associateWith { it.icon }

    @Test
    fun everyGroupHasTheMapsGlyph() {
        for (goal in SettingsGoal.entries) {
            val entry = groups.getValue(goal.name.lowercase()).jsonObject
            assertEquals("group ${goal.name}", expected(entry), goal.icon.name)
        }
    }

    @Test
    fun everyPageHasTheMapsGlyph() {
        for ((_, list) in SettingsTree.goals) {
            for (page in list) {
                val entry = pages.getValue(mapId(page)).jsonObject
                assertEquals("page $page", expected(entry), SettingsTree.pageIcons.getValue(page).name)
            }
        }
        // Sign out, at the end of Settings.
        assertEquals(expected(pages.getValue("signout").jsonObject), EchoIcons.SignOut.name)
    }

    @Test
    fun oneMeaningOneGlyph() {
        val groupGlyphs = goalIcons.values.map { it.name }
        assertEquals("two groups share a glyph", groupGlyphs.size, groupGlyphs.toSet().size)
        for ((goal, list) in SettingsTree.goals) {
            val glyphs = list.map { SettingsTree.pageIcons.getValue(it).name }
            assertEquals("two pages of ${goal.name} share a glyph", glyphs.size, glyphs.toSet().size)
            if (list.size > 1) assertFalse("${goal.name} repeats a page's glyph", goal.icon.name in glyphs)
        }
    }

    @Test
    fun onlySignOutAndHelpMirrorInRightToLeft() {
        for (goal in SettingsGoal.entries) assertFalse("group ${goal.name} mirrors", goal.icon.autoMirror)
        for ((page, icon) in SettingsTree.pageIcons) assertEquals("page $page", page == "help", icon.autoMirror)
        assertTrue("Sign out mirrors", EchoIcons.SignOut.autoMirror)
    }
}
