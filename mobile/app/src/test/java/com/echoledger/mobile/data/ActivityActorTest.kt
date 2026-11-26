package com.echoledger.mobile.data

import com.echoledger.mobile.ui.v2.activity.ActivityPeople
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * Contract 2.0.0-aa on the phone: an Activity item's `actor`, read leniently as the desktop does (a bad actor never
 * costs the item or the list), whose face shows only for the neutral kinds (decision 27), in the server's colour.
 */
class ActivityActorTest {
    private fun page(vararg actors: String): List<ActivityItemDto> = ApiJson.decodeFromString(
        ActivityResponse.serializer(),
        """{"data": [${actors.mapIndexed { i, actor ->
            """{"id": "n$i", "kind": "shared_with_you", "title": "Sent", "body": "", "read": false, "at": "2026-11-26T09:00:00Z"$actor}"""
        }.joinToString(",")}]}"""
    ).data

    @Test
    fun anActorWithEveryFieldIsRead() {
        val actor = page(
            ""","actor": {"id": 7, "name": "Ayesha Khan", "avatar_url": "https://ledger.test/api/avatars/7/1763700000?signature=x", "avatar_version": 1763700000, "avatar_color": 3}"""
        )
            .single().actor
        assertEquals(ActorDto(7, "Ayesha Khan", "https://ledger.test/api/avatars/7/1763700000?signature=x", 1763700000, 3), actor)
    }

    @Test
    fun noKeyOrNullIsNoPerson() {
        assertEquals(listOf(null, null), page("", ""","actor": null""").map { it.actor })
    }

    @Test
    fun aBadActorNeverCostsTheItemOrTheList() {
        val items = page(
            ""","actor": {"id": 1, "name": "Omar"}""",
            ""","actor": "Ana"""",
            ""","actor": []""",
            ""","actor": {"name": 5}""",
            ""","actor": 42""",
            ""","actor": {"id": 2, "name": "Lina"}"""
        )
        assertEquals(6, items.size)
        assertEquals(listOf("Omar", null, null, null, null, "Lina"), items.map { it.actor?.name })
    }

    @Test
    fun aFieldOfAnotherTypeIsLeftOutAndThePersonKept() {
        val actor = page(
            ""","actor": {"id": "42", "name": "Ana", "avatar_url": 5, "avatar_version": "soon", "avatar_color": "blue"}"""
        ).single().actor
        assertEquals(ActorDto(42, "Ana", null, null, null), actor)
        assertNull(page(""","actor": {"id": "4.2", "name": "Ana"}""").single().actor?.id)
        assertNull(page(""","actor": {"id": -3, "name": "Ana"}""").single().actor?.id)
    }

    @Test
    fun onlyTheNeutralKindsShowTheirPerson() {
        val ana = ActorDto(5, "Ana")
        fun item(kind: String) = ActivityItemDto(id = kind, kind = kind, actor = ana)
        assertEquals(ana, ActivityPeople.shownPerson(item("shared_with_you")))
        assertEquals(ana, ActivityPeople.shownPerson(item("upgrade_requested")))
        // A warning, a failure or a receipt asked for keeps its icon, whoever it is about.
        for (kind in listOf("unusual_usage", "upgrade_receipt_requested", "transcript_failed", "missed_recording", "budget_warn")) {
            assertNull(kind, ActivityPeople.shownPerson(item(kind)))
        }
        assertNull(ActivityPeople.shownPerson(ActivityItemDto(id = "x", kind = null, actor = ana)))
    }

    @Test
    fun theColourIsTheServersThenTheIdModuloEightThenTheNamesSeed() {
        assertEquals(3, ActivityPeople.colourOf(ActorDto(id = 42, avatarColor = 3)))
        assertEquals(2, ActivityPeople.colourOf(ActorDto(id = 42, avatarColor = null)))
        assertEquals(5, ActivityPeople.colourOf(ActorDto(id = 13, avatarColor = 9)))
        assertEquals(4, ActivityPeople.colourOf(ActorDto(name = "Ana")))
        assertEquals(4, ActivityPeople.colourOf(ActorDto(name = "  ana ")))
        assertEquals(1, ActivityPeople.colourOf(ActorDto(name = "Omar Malik")))
    }
}
