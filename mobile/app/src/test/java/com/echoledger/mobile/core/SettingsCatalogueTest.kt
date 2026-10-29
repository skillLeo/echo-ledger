package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SettingsCatalogueTest {
    @Test
    fun `the groups are named and ordered like the desktop app and the console`() {
        assertEquals(
            // "Voice notes & files" (since v1.3, after sharing a voice note) comes first on the phone; plan B5 adds it elsewhere.
            listOf("General", "Recording", "Voice notes & files", "Captions", "Dictation", "Privacy & security", "Devices", "Storage", "About"),
            SettingsGroup.entries.map { it.label }
        )
    }

    @Test
    fun `every group has settings and every setting has a unique id`() {
        SettingsGroup.entries.forEach { assertTrue("${it.label} is empty", SettingsCatalogue.inGroup(it).isNotEmpty()) }
        val ids = SettingsCatalogue.entries.map { it.id }
        assertEquals(ids.size, ids.toSet().size)
    }

    @Test
    fun `search finds a setting by its name its keywords and its group`() {
        assertEquals(listOf("notifications"), SettingsCatalogue.search("quiet hours").map { it.id })
        assertEquals(listOf("app-lock"), SettingsCatalogue.search("Fingerprint").map { it.id })
        assertEquals(listOf("storage"), SettingsCatalogue.search("retention").map { it.id })
        assertEquals(SettingsCatalogue.inGroup(SettingsGroup.Captions).map { it.id }, SettingsCatalogue.search("captions").map { it.id })
    }

    @Test
    fun `search needs every word and ignores case and extra spaces`() {
        assertEquals(listOf("dictation"), SettingsCatalogue.search("  PUSH   talk ").map { it.id })
        assertTrue(SettingsCatalogue.search("push banana").isEmpty())
    }

    @Test
    fun `a blank query finds nothing`() {
        assertTrue(SettingsCatalogue.search("").isEmpty())
        assertTrue(SettingsCatalogue.search("   ").isEmpty())
    }

    @Test
    fun `the wizard steps run in order and stop at the ends`() {
        assertEquals(
            listOf("Welcome", "Permissions", "Pair", "Test", "Done"),
            OnboardingStep.entries.map { it.title }
        )
        assertEquals(OnboardingStep.Permissions, OnboardingStep.Welcome.next)
        assertEquals(OnboardingStep.Done, OnboardingStep.Done.next)
        assertEquals(OnboardingStep.Welcome, OnboardingStep.Welcome.previous)
        assertEquals(OnboardingStep.Pair, OnboardingStep.Test.previous)
        assertEquals("Step 3 of 5", OnboardingStep.Pair.label)
        assertEquals(OnboardingStep.Welcome, OnboardingStep.of("nonsense"))
        assertEquals(OnboardingStep.Test, OnboardingStep.of("Test"))
    }
}
