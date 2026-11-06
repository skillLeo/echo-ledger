package com.echoledger.mobile.core

import com.echoledger.mobile.data.LocalRecording
import kotlinx.serialization.Serializable

/**
 * Whose waiting recordings these are (since v1.5, docs/API.md "Workspaces, roles and privacy": a recording belongs to
 * the person who made it; the desktop app's rule, `owner.rs`). The phone remembers the account it signs in as
 * ([com.echoledger.mobile.data.AppSettings.account], kept after signing out). A recording made while that account is
 * the one here carries no owner of its own; when someone else signs in, every such recording is first stamped with the
 * account it was made under, so it never uploads into another person's library or to another server, and waits for
 * that account to sign in again.
 */
@Serializable
data class Owner(val server: String, val email: String? = null) {
    /**
     * What was made under this account may upload while [current] is signed in: the same server, and the same account
     * when this one's is known. A known account never uploads while the signed-in one is unknown.
     */
    fun mayUploadAs(current: Owner): Boolean = server.equals(current.server, ignoreCase = true) &&
        when {
            email == null -> true
            current.email == null -> false
            else -> email == current.email
        }

    /** The account as the app names it: the email, else the server's address. */
    fun label(): String = email ?: server.removePrefix("https://").removePrefix("http://")

    companion object {
        fun of(server: String, email: String?): Owner =
            Owner(server.trim().trimEnd('/'), email?.trim()?.lowercase()?.takeIf { it.isNotEmpty() })

        /** Whether a waiting recording (its [owner], null = the current account's) may upload while [current] is signed in. */
        fun mayUpload(owner: Owner?, current: Owner?): Boolean = owner == null || (current != null && owner.mayUploadAs(current))

        /** The Record tab's line for recordings that wait for another account. */
        fun waitingLine(count: Int, owner: Owner): String {
            val what = if (count == 1) "1 recording waits" else "$count recordings wait"
            return "$what for ${owner.label()}: sign in as that account to send them. They stay on this phone until then."
        }

        /** The account a waiting recording waits for, when it is not the one signed in now; null when it may upload. */
        fun heldFor(owner: Owner?, current: Owner?): Owner? = owner?.takeUnless { mayUpload(it, current) }

        /**
         * A sign-in as [next] after [previous]: when it is someone else, the recordings without an owner are stamped
         * with [previous] (they were made under it). Returns only the recordings that changed.
         */
        fun stamp(recordings: List<LocalRecording>, previous: Owner?, next: Owner): List<LocalRecording> {
            if (previous == null || previous.mayUploadAs(next)) return emptyList()
            return recordings.filter { it.owner == null }.map { it.copy(owner = previous) }
        }
    }
}
