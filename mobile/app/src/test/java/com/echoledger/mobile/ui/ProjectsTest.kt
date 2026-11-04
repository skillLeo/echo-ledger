package com.echoledger.mobile.ui

import com.echoledger.mobile.core.AppLinks
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.FilingRuleDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.ShareLinkDto
import com.echoledger.mobile.testsupport.AppTest
import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Projects (since v1.5): the tree, where a project may move, the smart folders, links, and the share wording. */
class ProjectsTest : AppTest() {
    private fun p(id: String, parent: String? = null, name: String = id, pinned: Boolean = false) =
        ClientDto(id, name, parentId = parent, pinned = pinned)

    private val tree = listOf(
        p("acme", name = "Acme"),
        p("inv", "acme", "Invoices"),
        p("q4", "inv", "Q4"),
        p("beta", name = "Beta", pinned = true),
        p("orphan", "gone", "Lost parent")
    )

    private fun item(id: String, app: String, client: String? = null, deleted: Boolean = false) = LibraryItem(
        id = id,
        sourceApp = app,
        client = client?.let { NamedRef(it, it) },
        startedAt = "2026-11-0${id.length}T10:00:00Z",
        updatedAt = "2026-11-04T10:00:00Z",
        deleted = deleted
    )

    @Test
    fun `the tree has its roots first by pin and name, children, breadcrumbs and descendants`() {
        assertEquals(listOf("beta", "acme", "orphan"), ProjectTree.roots(tree).map { it.id })
        assertEquals(listOf("inv"), ProjectTree.children(tree, "acme").map { it.id })
        assertEquals(listOf("acme", "inv"), ProjectTree.ancestors(tree, "q4").map { it.id })
        assertEquals(setOf("inv", "q4"), ProjectTree.descendants(tree, "acme"))
        assertEquals(3, ProjectTree.depth(tree, "q4"))
        assertEquals(3, ProjectTree.height(tree, "acme"))
        assertEquals(
            listOf("beta" to 0, "acme" to 0, "inv" to 1, "q4" to 2, "orphan" to 0),
            ProjectTree.flatten(tree).map {
                it.first.id to
                    it.second
            }
        )
    }

    @Test
    fun `a project never moves under itself or its own folders, nor deeper than four levels`() {
        assertFalse(ProjectTree.canMove(tree, "acme", "acme"))
        assertFalse(ProjectTree.canMove(tree, "acme", "q4"))
        assertTrue(ProjectTree.canMove(tree, "q4", null))
        assertTrue(ProjectTree.canMove(tree, "beta", "q4")) // depth 3 + height 1 = 4
        val deep = tree + p("d1", "beta") + p("d2", "d1")
        assertFalse("acme is 3 levels; under beta's 2-level line it would reach 5", ProjectTree.canMove(deep, "acme", "d1"))
        // A cycle in bad data does not hang the breadcrumbs.
        val loop = listOf(p("a", "b"), p("b", "a"))
        assertEquals(listOf("a"), ProjectTree.ancestors(loop, "b").map { it.id })
    }

    @Test
    fun `smart folders are views of the library, and a project lists its own or also its folders' recordings`() {
        val library = listOf(
            item("w1", "whatsapp"),
            item("wb2", "whatsapp_business", client = "acme"),
            item("zoom33", "zoom", client = "q4"),
            item("pc4444", "phone_call"),
            item("gone5", "whatsapp", deleted = true)
        )
        assertEquals(listOf("w1"), library.filter(SmartFolder.WHATSAPP::matches).map { it.id })
        assertEquals(listOf("w1", "pc4444"), library.filter(SmartFolder.INBOX::matches).map { it.id })
        assertEquals(listOf("pc4444"), library.filter(SmartFolder.PHONE::matches).map { it.id })
        assertTrue(library.none(SmartFolder.SHARED::matches))
        assertEquals(listOf("wb2"), ProjectTree.recordings(tree, library, "acme", nested = false).map { it.id })
        assertEquals(listOf("zoom33", "wb2"), ProjectTree.recordings(tree, library, "acme", nested = true).map { it.id })
        assertEquals(SmartFolder.SHARED, SmartFolder.of("shared"))
        assertNull(SmartFolder.of("nope"))
    }

    @Test
    fun `only share, project and request links of https open in the app`() {
        val view = android.content.Intent.ACTION_VIEW
        assertEquals(
            "https://example.test/s/abc",
            AppLinks.link(view, android.net.Uri.parse("echoledger://open?link=https%3A%2F%2Fexample.test%2Fs%2Fabc"))
        )
        assertEquals("https://example.test/p/xyz", AppLinks.link(view, android.net.Uri.parse("https://example.test/p/xyz")))
        assertNull(AppLinks.link(view, android.net.Uri.parse("https://example.test/admin")))
        assertNull(AppLinks.link(view, android.net.Uri.parse("echoledger://open?link=http%3A%2F%2Fexample.test%2Fs%2Fabc")))
        assertNull(AppLinks.link(view, android.net.Uri.parse("https://example.test/r/")))
        assertNull(AppLinks.link(android.content.Intent.ACTION_MAIN, android.net.Uri.parse("https://example.test/s/abc")))
    }

    @Test
    fun `emails, expiry, and how links and rules read`() {
        assertEquals(
            listOf("ana@example.com", "omar@example.com"),
            LinkExpiry.emails("ana@example.com, omar@example.com;nope ana@example.com")
        )
        assertNull(LinkExpiry.at(0))
        assertEquals("2026-11-11T10:00:00Z", LinkExpiry.at(7, Instant.parse("2026-11-04T10:00:00Z")))
        assertEquals("Only ana@example.com", ShareText.audience(ShareLinkDto("1", audience = "people", people = listOf("ana@example.com"))))
        assertEquals("Anyone with the link", ShareText.audience(ShareLinkDto("2")))
        assertEquals("3 views · no downloads", ShareText.meta(ShareLinkDto("3", viewCount = 3, allowDownload = false)))
        assertEquals("Title contains \"Acme\"", FilingRuleText.label(FilingRuleDto(1, "title", "Acme")))
        assertEquals("From WhatsApp Business", FilingRuleText.label(FilingRuleDto(2, "app", "whatsapp_business")))
    }
}
