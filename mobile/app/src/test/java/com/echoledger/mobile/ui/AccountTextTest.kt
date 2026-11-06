package com.echoledger.mobile.ui

import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountWorkspaceDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The words of You → Account (since v1.5) and the web page an account without a password confirms its deletion on. */
class AccountTextTest {
    @Test
    fun `the public deletion page is on the signed-in server`() {
        assertEquals("https://ledger.example.com/account/delete", AccountText.deletePage("https://ledger.example.com/"))
        assertEquals("https://ledger.example.com/account/delete", AccountText.deletePage("https://ledger.example.com"))
        assertNull(AccountText.deletePage(null))
        assertNull(AccountText.deletePage(""))
    }

    @Test
    fun `a Google account is told it signs in there with Google`() {
        assertTrue(AccountText.deleteElsewhere(google = true).contains("sign in there with Google"))
        assertTrue(AccountText.deleteElsewhere(google = false).startsWith("No password or code"))
    }

    @Test
    fun `the workspace and security lines`() {
        val account = AccountDto(
            name = "Hassam",
            email = "h@example.com",
            emailVerified = false,
            twoFactor = true,
            google = true,
            workspace = AccountWorkspaceDto(id = 1, name = "Acme team", role = "viewer")
        )
        assertEquals("Acme team · Viewer", AccountText.workspace(account))
        assertEquals("Two-factor sign-in on · Google linked · Email not verified", AccountText.security(account))
    }
}
