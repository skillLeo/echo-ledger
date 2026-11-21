package com.echoledger.mobile.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonObjectBuilder
import kotlinx.serialization.json.put

/*
 * The person's own account and security from the apps (docs/API.md "Account and security from the apps", contract
 * 2.0.0-l): the answers of /api/account/... and what the phone sends as proof.
 */

/** `password_rules` of `GET /api/auth/options`: what a new password needs. */
@Serializable
data class PasswordRulesDto(val min: Int = 12, val letters: Boolean = true, val numbers: Boolean = true)

/** `POST /api/account/avatar`: the new picture's signed address and its version. */
@Serializable
data class AvatarDto(@SerialName("avatar_url") val avatarUrl: String? = null, @SerialName("avatar_version") val avatarVersion: Long? = null)

/** `POST /api/account/email`: the address waiting for its code, and until when (60 minutes). */
@Serializable
data class PendingEmailDto(
    @SerialName("pending_email") val pendingEmail: String? = null,
    @SerialName("pending_email_expires_at") val pendingEmailExpiresAt: String? = null
)

/** `GET /api/account/security`: the ways in, the browsers and devices of every workspace, and the latest events. */
@Serializable
data class AccountSecurityDto(
    val password: Boolean = false,
    val google: GoogleLinkDto = GoogleLinkDto(),
    val passkeys: List<PasskeyDto> = emptyList(),
    @SerialName("two_factor") val twoFactor: TwoFactorStateDto = TwoFactorStateDto(),
    val browsers: List<BrowserDto> = emptyList(),
    val devices: List<AccountDeviceDto> = emptyList(),
    val events: List<SecurityEventDto> = emptyList()
)

/** Google's link; [email] is always null (the server keeps only Google's id of the person). */
@Serializable
data class GoogleLinkDto(val linked: Boolean = false, val email: String? = null)

@Serializable
data class PasskeyDto(
    val id: String,
    val name: String = "",
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("last_used_at") val lastUsedAt: String? = null
)

@Serializable
data class TwoFactorStateDto(val enabled: Boolean = false, @SerialName("recovery_codes_left") val recoveryCodesLeft: Int? = null)

/** A console browser signed in to the account; its [id] works only to sign it out. */
@Serializable
data class BrowserDto(
    val id: String,
    val agent: String = "",
    val country: String? = null,
    @SerialName("last_active_at") val lastActiveAt: String? = null,
    val current: Boolean = false
)

/** A device of any of the person's workspaces; signed-out ones stay listed for 30 days. */
@Serializable
data class AccountDeviceDto(
    val id: String,
    val name: String = "",
    val platform: String = "",
    @SerialName("app_version") val appVersion: String? = null,
    @SerialName("last_seen_at") val lastSeenAt: String? = null,
    @SerialName("paired_at") val pairedAt: String? = null,
    val current: Boolean = false,
    val status: String = "active",
    @SerialName("signed_out_at") val signedOutAt: String? = null,
    val workspace: DeviceWorkspaceDto? = null
)

@Serializable
data class DeviceWorkspaceDto(val id: Long? = null, val name: String? = null)

/** `POST /api/account/two-factor`: the authenticator's secret, valid for 10 minutes until confirmed. */
@Serializable
data class TwoFactorSetupDto(
    val ceremony: String,
    val secret: String,
    @SerialName("otpauth_uri") val otpauthUri: String,
    val issuer: String? = null,
    val label: String? = null,
    val digits: Int = 6,
    val period: Int = 30,
    val algorithm: String = "SHA1"
)

/** Recovery codes: shown once, when two-step is turned on or the codes are made again. */
@Serializable
data class RecoveryCodesDto(@SerialName("recovery_codes") val recoveryCodes: List<String> = emptyList())

/** `POST /api/account/passkeys/options`: the WebAuthn creation options Credential Manager takes as they are. */
@Serializable
data class PasskeyOptionsDto(val ceremony: String, val options: JsonObject)

/** `POST /api/account/passkeys`: the passkey just added. */
@Serializable
data class AddedPasskeyDto(val id: String, val name: String = "", @SerialName("created_at") val createdAt: String? = null)

/**
 * Proof that the person is the account holder: the password, an authenticator or recovery code, or (for an account
 * with neither a password nor two-step) a code emailed by `POST /api/account/proof-code`. Blank parts are left out.
 */
data class Proof(val password: String? = null, val otp: String? = null, val emailCode: String? = null) {
    fun into(json: JsonObjectBuilder) {
        password?.takeIf { it.isNotEmpty() }?.let { json.put("password", it) }
        otp?.trim()?.replace(" ", "")?.takeIf { it.isNotEmpty() }?.let { json.put("otp", it) }
        emailCode?.trim()?.replace(" ", "")?.takeIf { it.isNotEmpty() }?.let { json.put("email_code", it) }
    }

    val isEmpty: Boolean get() = password.isNullOrEmpty() && otp.isNullOrBlank() && emailCode.isNullOrBlank()
}
