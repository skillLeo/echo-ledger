package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SettingsCatalogueTest {
    @Test
    fun `the groups are named and ordered like the desktop app and the console`() {
        assertEquals(
            // Since v1.3 (plan B5): General, then one panel per mode of the Record sheet.
            listOf("General", "Voice notes & files", "Calls & meetings", "Game translate", "Watch translate"),
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
        assertEquals(listOf("captions", "caption-style"), SettingsCatalogue.search("captions").map { it.id })
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

    @Test
    fun `resetting a panel restores its defaults and keeps what the person made or picked`() {
        val custom = com.echoledger.mobile.data.AppSettings(
            serverUrl = "https://example.test",
            deviceId = "01DEVICE",
            autoRecordArmed = true,
            themeMode = "dark",
            pillEdge = "left",
            recordingKbps = 64,
            extraCallApps = listOf("com.example.voip"),
            dictationMode = "push_to_talk",
            liveTranslate = com.echoledger.mobile.translate.LiveTranslatePrefs(
                game = com.echoledger.mobile.translate.GamePrefs(games = listOf("com.tencent.ig"), target = "ur", profanityMask = true),
                watch = com.echoledger.mobile.translate.WatchPrefs(apps = listOf("com.google.android.youtube"), target = "en"),
                monthlyBudgetUsd = 50.0
            )
        )

        val calls = SettingsReset.reset(SettingsGroup.Calls, custom)
        assertEquals("right", calls.pillEdge)
        assertEquals(32, calls.recordingKbps)
        assertEquals("auto-record stays on", true, calls.autoRecordArmed)
        assertEquals(listOf("com.example.voip"), calls.extraCallApps)
        assertEquals("dark", calls.themeMode)

        val game = SettingsReset.reset(SettingsGroup.Game, custom).liveTranslate
        assertEquals(listOf("com.tencent.ig"), game.game.games)
        assertEquals("en", game.game.target)
        assertEquals(false, game.game.profanityMask)
        assertEquals(10.0, game.monthlyBudgetUsd, 0.0)
        assertEquals("the other mode is untouched", "en", game.watch.target)

        val watch = SettingsReset.reset(SettingsGroup.Watch, custom).liveTranslate.watch
        assertEquals(listOf("com.google.android.youtube"), watch.apps)
        assertEquals("ur", watch.target)

        val general = SettingsReset.reset(SettingsGroup.General, custom)
        assertEquals("system", general.themeMode)
        assertEquals("pairing stays", "01DEVICE", general.deviceId)
        assertEquals("toggle", SettingsReset.reset(SettingsGroup.VoiceNotes, custom).dictationMode)
    }
}
