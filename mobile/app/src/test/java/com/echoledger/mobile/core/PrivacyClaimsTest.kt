package com.echoledger.mobile.core

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The phone never promises more privacy than it gives: a private window is hidden in a screen share, but people in the
 * room, a camera or another app can still see the screen. So no sentence says that only the person sees something, or
 * that it is invisible, in any language.
 */
class PrivacyClaimsTest {
    private val forbidden = listOf(
        Regex("""only\s+you\s+(can\s+)?see""", RegexOption.IGNORE_CASE),
        Regex("""invisible""", RegexOption.IGNORE_CASE),
        // Urdu: "only you see" / "only you can see".
        Regex("""صرف\s+آپ\s+(ہی\s+)?دیکھ"""),
        Regex("""صرف\s+آپ\s+کو\s+نظر""")
    )

    private fun strings(): Map<String, String> = File("src/main/res").listFiles { f -> f.name.startsWith("values") }.orEmpty()
        .flatMap { dir -> dir.listFiles { f -> f.extension == "xml" }.orEmpty().toList() }
        .flatMap { file ->
            Regex("""<string name="([^"]+)"[^>]*>(.*?)</string>""", RegexOption.DOT_MATCHES_ALL).findAll(file.readText())
                .map { "${file.parentFile.name}/${it.groupValues[1]}" to it.groupValues[2] }.toList()
        }.toMap()

    @Test
    fun noPhoneStringClaimsOnlyThePersonSeesItOrThatItIsInvisible() {
        val all = strings()
        assertTrue("the phone's strings were found", all.size > 500)
        val claims = all.filter { (_, text) -> forbidden.any { it.containsMatchIn(text) } }.keys
        assertEquals(emptySet<String>(), claims)
    }

    @Test
    fun theRuleCatchesTheOldSentence() {
        val old = "Suggestions appear on a card over your call while the other person talks. Only you see it, and it is hidden."
        assertTrue(forbidden.any { it.containsMatchIn(old) })
        assertTrue(forbidden.any { it.containsMatchIn("یہ صرف آپ دیکھتے ہیں") })
        assertTrue(forbidden.any { it.containsMatchIn("Only you can see the card") })
    }

    @Test
    fun theLiveAssistStartSaysPrivateCardHiddenInAScreenShare() {
        val all = strings()
        assertEquals(
            "Suggestions appear on a private card over your call while the other person talks, hidden when you share your screen.",
            all["values/v2_assist_start_intro"]
        )
    }
}
