package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileCardSlot
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UpgradeLook
import com.echoledger.mobile.ui.v2.shell.upgradeLook
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Settings v2's profile card against classic's (docs/v2/audit/parity.csv PH-SET-001, 002): name, email, plan and
 * minutes, a tap opens Plan & usage; Upgrade filled on Free, outlined on a paid plan, none on the top plan, while a
 * request is open or for a team member.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class V2ProfileCardParityTest {
    @get:Rule
    val compose = createComposeRule()

    private val offers = listOf(
        PlanOfferDto("free", "Free", pricePkr = 0),
        PlanOfferDto("pro", "Pro", pricePkr = 2_500),
        PlanOfferDto("ultra", "Ultra", pricePkr = 6_000)
    )

    private fun plan(key: String, role: String? = "owner", request: UpgradeRequestDto? = null) =
        PlanState(name = key.replaceFirstChar { it.uppercase() }, key = key, role = role, offers = offers, upgrade = request)

    @Test
    fun upgradeIsFilledOnFreeOutlinedOnAPaidPlanAndAbsentOnTheTopOneOrWhileARequestIsOpen() {
        assertEquals(UpgradeLook.Filled, upgradeLook(plan("free"), signedIn = true, manual = true))
        assertEquals(UpgradeLook.Outlined, upgradeLook(plan("pro"), signedIn = true, manual = true))
        assertNull(upgradeLook(plan("ultra"), signedIn = true, manual = true))
        assertNull(
            upgradeLook(
                plan("free", request = UpgradeRequestDto(id = "r1", plan = "pro", status = "pending")),
                signedIn = true,
                manual = true
            )
        )
        // A decided request no longer holds it back.
        assertEquals(
            UpgradeLook.Filled,
            upgradeLook(
                plan("free", request = UpgradeRequestDto(id = "r1", plan = "pro", status = "declined")),
                signedIn = true,
                manual = true
            )
        )
        assertNull(upgradeLook(plan("free", role = "member"), signedIn = true, manual = true))
        assertNull(upgradeLook(plan("free"), signedIn = false, manual = true))
        // The Google Play build sells nothing here.
        assertNull(upgradeLook(plan("free"), signedIn = true, manual = false))
    }

    private val profile = Profile("Ayesha Siddiqui", "ayesha@bilaltraders.pk", "Free", 42, 120, 2_100_000_000, 31)

    private fun card(upgrade: UpgradeLook?, onOpenPlan: () -> Unit = {}) = compose.setContent {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            ProfileCardSlot(ProfileState.Ready(profile), {}, {}, upgrade = upgrade, onOpenPlan = onOpenPlan)
        }
    }

    @Test
    fun theCardSaysWhoThePlanAndTheMinutesAndATapOpensPlanAndUsage() {
        var opened = 0
        card(UpgradeLook.Filled) { opened++ }
        compose.onNodeWithText("Ayesha Siddiqui", substring = true).assertExists()
        compose.onNodeWithText("ayesha@bilaltraders.pk", substring = true).assertExists()
        compose.onAllNodesWithText("Free", substring = true).fetchSemanticsNodes().let { assertEquals(true, it.isNotEmpty()) }
        compose.onAllNodesWithText("42", substring = true).fetchSemanticsNodes().let { assertEquals(true, it.isNotEmpty()) }
        compose.onNodeWithText("Ayesha Siddiqui", substring = true).performSemanticsAction(SemanticsActions.OnClick)
        assertEquals(1, opened)
        compose.onAllNodes(hasText("Upgrade") and hasClickAction()).assertCountEquals(1)
    }

    @Test
    fun onTheTopPlanThereIsNoUpgrade() {
        card(upgrade = null)
        compose.onAllNodesWithText("Upgrade").assertCountEquals(0)
    }

    @Test
    fun onAPaidPlanUpgradeIsStillOffered() {
        var opened = 0
        card(UpgradeLook.Outlined) { opened++ }
        compose.onNodeWithText("Upgrade").performClick()
        // Upgrade is its own button: it does not open Plan & usage.
        assertEquals(0, opened)
    }

    @Test
    fun theStorageLineSaysHowLongAudioIsKept() {
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                ProfileCardSlot(ProfileState.Ready(profile.copy(retentionDays = 30)), {}, {})
            }
        }
        compose.onNodeWithText("Audio kept: 2.1 GB in 31 recordings · cleaned up automatically after 30 days").assertExists()
    }
}
