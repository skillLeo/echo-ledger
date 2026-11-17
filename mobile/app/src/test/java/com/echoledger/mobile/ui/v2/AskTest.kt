package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.data.AskAnswerDto
import com.echoledger.mobile.data.CitationDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.ui.v2.ask.AskState
import com.echoledger.mobile.ui.v2.ask.V2AskContent
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Ask v2: the project scope, the question, the answer with its recordings, and its states. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class AskTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")

    private val projects = listOf(ClientDto("01JC1", "Bilal Traders"), ClientDto("01JC2", "Shaheen Pharma Distributors"))

    private val answer = AskAnswerDto(
        "Bilal Traders agreed to pay in 45 days at Rs 4,200 a carton, and asked for their PO number on every invoice.",
        listOf(
            CitationDto(
                "session",
                sessionId = "01JA",
                title = "Bilal Traders",
                atMs = 26_000,
                quote = "Payment in 45 days as usual, and put our PO number on the invoice."
            ),
            CitationDto(
                "session",
                sessionId = "01JB",
                title = "Bilal Traders follow-up",
                atMs = 128_000,
                quote = "Same terms for the Sukkur order."
            )
        )
    )

    @Composable
    private fun Ask(
        state: AskState,
        question: String,
        asked: MutableList<String>,
        opened: MutableList<Pair<String, Long>>,
        list: List<ClientDto> = projects
    ) = V2AskContent(list, list.firstOrNull()?.id, {}, question, {}, state, { asked += it }, { id, ms -> opened += id to ms })

    @Test
    fun askNeedsAQuestionAndAsksTheChosenProject() {
        var question by mutableStateOf("")
        val asked = mutableListOf<String>()
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2AskContent(projects, "01JC1", {}, question, { question = it }, AskState.Idle, { asked += it }, { _, _ -> })
            }
        }
        compose.onNode(hasText("Ask") and hasClickAction()).assertIsNotEnabled()
        question = "What did Bilal Traders agree on payment?"
        compose.onNode(hasText("Ask") and hasClickAction()).assertIsEnabled().performClick()
        assertEquals(listOf("01JC1"), asked)
        compose.onNodeWithText("All recordings (not available yet)").assertExists()
    }

    @Test
    fun theAnswerSaysWhereItComesFromAndOpensIt() {
        val opened = mutableListOf<Pair<String, Long>>()
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) { Ask(AskState.Answer(answer), "q", mutableListOf(), opened) }
        }
        compose.onNodeWithText("Bilal Traders follow-up").performScrollTo().performClick()
        assertEquals(listOf("01JB" to 128_000L), opened)
    }

    @Test
    fun aFailedAskOffersToAskAgainAndNoProjectsSaysWhatToDo() {
        val asked = mutableListOf<String>()
        var state by mutableStateOf<AskState>(AskState.Failed)
        var list by mutableStateOf(projects)
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) { Ask(state, "q", asked, mutableListOf(), list) }
        }
        compose.onNodeWithText("The answer couldn't be made", substring = true).assertExists()
        list = emptyList()
        compose.onNodeWithText("No projects yet").assertExists()
    }

    @Test
    fun screenshots() {
        var language by mutableStateOf(EchoLanguage.English)
        var which by mutableStateOf("ask-answer")
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = language) {
                val q = if (language ==
                    EchoLanguage.Urdu
                ) {
                    "بلال ٹریڈرز نے ادائیگی پر کیا طے کیا؟"
                } else {
                    "What did Bilal Traders agree on payment?"
                }
                when (which) {
                    "ask-answer" -> Ask(AskState.Answer(answer), q, mutableListOf(), mutableListOf())
                    else -> Ask(AskState.Idle, "", mutableListOf(), mutableListOf())
                }
            }
        }
        for (name in listOf("ask-answer", "ask")) {
            for (l in EchoLanguage.entries) {
                which = name
                language = l
                compose.waitForIdle()
                compose.onRoot().captureRoboImage(File(outDir, "$name-light-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"))
            }
        }
    }
}
