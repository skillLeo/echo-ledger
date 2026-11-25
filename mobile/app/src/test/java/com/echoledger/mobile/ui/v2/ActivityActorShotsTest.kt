package com.echoledger.mobile.ui.v2

import android.graphics.Bitmap
import android.graphics.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.account.AvatarCache
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.ActorDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.ActivityScreen
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.v2.activity.ACTOR_AVATAR_TAG
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.ActorLook
import com.echoledger.mobile.ui.v2.activity.ActorLookKey
import com.echoledger.mobile.ui.v2.activity.V2ActivityContent
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.ByteArrayOutputStream
import java.io.File
import java.time.LocalDate
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Contract 2.0.0-aa on the phone, drawn: a shared recording's sender in the icon's place (their picture kept on the
 * phone, else their initials on their colour), an upgrade request's person in Urdu, a picture the server no longer
 * gives (403) as initials, and a warning that keeps its icon. v2 in English and Urdu, the classic list, light and dark,
 * at the S25's size, in docs/v2/design-review/phone/activity-actor.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(qualifiers = "w411dp-h891dp-420dpi")
class ActivityActorShotsTest : ScreenTest() {
    private val out =
        File(
            File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile,
            "v2/design-review/phone/activity-actor"
        )
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)

    /** A small square picture, as the server would send one. */
    private fun picture(): ByteArray {
        val bitmap = Bitmap.createBitmap(64, 64, Bitmap.Config.ARGB_8888)
        Canvas(bitmap).drawColor(0xFF6B4F3A.toInt())
        return ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }.toByteArray()
    }

    private fun items(origin: String) = ActivityResponse(
        listOf(
            ActivityItemDto(
                "a1",
                "shared_with_you",
                "Ayesha Khan sent you Payment terms",
                "Open it in Shared with me.",
                "s-a1",
                at = "2026-11-21T09:00:00Z",
                actor = ActorDto(7, "Ayesha Khan", "$origin/api/avatars/7/1?expires=1&signature=x", 1, 3)
            ),
            ActivityItemDto(
                "a2",
                "shared_with_you",
                "Omar Malik sent you Weekly sync",
                "Open it in Shared with me.",
                "s-a2",
                at = "2026-11-21T08:30:00Z",
                actor = ActorDto(9, "Omar Malik", "$origin/api/avatars/9/1?expires=1&signature=x", 1, 1)
            ),
            ActivityItemDto(
                "a3",
                "upgrade_requested",
                "عائشہ خان asked for Pro",
                "Receipt attached",
                null,
                at = "2026-11-21T08:00:00Z",
                actor = ActorDto(13, "عائشہ خان")
            ),
            ActivityItemDto(
                "a4",
                "unusual_usage",
                "Unusual use",
                "Farah Malik recorded 9 hours in a day.",
                null,
                at = "2026-11-21T07:00:00Z",
                actor = ActorDto(8, "Farah Malik", avatarColor = 4)
            )
        )
    )

    private fun seed(): ActivityResponse {
        runBlocking { app.pairLocally(server) }
        // Omar's picture is gone (a 403): his initials, quietly.
        server.on("GET", "/api/avatars/.*", FakeServer.json(403, """{"message":"Forbidden","code":"forbidden"}"""))
        val origin = runBlocking { app.graph.api()!!.origin }
        AvatarCache.of(app, origin).put("7", 1, picture())
        return items(origin)
    }

    private fun looks() = compose.onAllNodes(hasTestTag(ACTOR_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes().map {
        it.config[ActorLookKey]
    }

    private val expected = listOf(
        ActorLook(picture = true, initials = null, colour = null),
        ActorLook(picture = false, initials = "OM", colour = 1),
        ActorLook(picture = false, initials = "ع‌خ", colour = 5)
    )

    @Test
    fun theNewActivityShowsThePeopleItemsAreAbout() {
        val answer = seed()
        show {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2ActivityContent(
                        load = { answer },
                        markRead = { 0 },
                        waiting = emptyList(),
                        failedUploads = emptyList(),
                        untranscribed = 0,
                        failedTranscripts = 0,
                        skipped = emptyList(),
                        missed = emptyList(),
                        actions = ActivityActions(onOpenRecording = {}, onFix = {}, onOpenUntranscribed = {}),
                        onKeep = {},
                        onSaveAudio = {},
                        onDiscard = {},
                        onRetryUploads = {},
                        onRestore = {},
                        onMissedRead = {},
                        onMissedDismiss = {},
                        today = LocalDate.parse("2026-11-21")
                    )
                }
            }
        }
        pumpUntil("the people's faces") { looks() == expected }
        for ((l, d) in listOf(
            EchoLanguage.English to false,
            EchoLanguage.English to true,
            EchoLanguage.Urdu to false,
            EchoLanguage.Urdu to true
        )) {
            language = l
            dark = d
            compose.waitForIdle()
            assertEquals(expected, looks())
            compose.onRoot().captureRoboImage(
                File(out, "v2-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png")
            )
        }
    }

    @Test
    fun theClassicListShowsThemInTheTilesPlace() {
        val answer = seed()
        show {
            EchoLedgerTheme(themeMode = if (dark) "dark" else "light") {
                // The whole page in the chosen theme (the test's own theme around it is light).
                androidx.compose.foundation.layout.Box(
                    androidx.compose.ui.Modifier.fillMaxSize().background(androidx.compose.material3.MaterialTheme.colorScheme.background)
                ) {
                    ActivityScreen(load = { answer }, markRead = { 0 }, onOpen = {}, onUnread = {})
                }
            }
        }
        pumpUntil("the people's faces") { looks() == expected }
        for (d in listOf(false, true)) {
            dark = d
            compose.waitForIdle()
            compose.onRoot().captureRoboImage(File(out, "classic-${if (d) "dark" else "light"}-en.png"))
        }
    }
}
