package com.echoledger.mobile.account

import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountSecurityDto
import com.echoledger.mobile.data.AddedPasskeyDto
import com.echoledger.mobile.data.AvatarDto
import com.echoledger.mobile.data.PasskeyOptionsDto
import com.echoledger.mobile.data.PasswordRulesDto
import com.echoledger.mobile.data.PendingEmailDto
import com.echoledger.mobile.data.Proof
import com.echoledger.mobile.data.RecoveryCodesDto
import com.echoledger.mobile.data.TwoFactorSetupDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.NotPairedException
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonObject

/**
 * The account and security calls of contract 2.0.0-l for the screens: each one answers [AccountResult], so a screen
 * shows the problem in its own words and never a raw message. [api] is this phone's client (null when signed out).
 */
class AccountActions(private val api: suspend () -> ApiClient?) {
    private suspend fun <T> run(block: suspend (ApiClient) -> T): AccountResult<T> = withContext(Dispatchers.IO) {
        try {
            AccountResult.Done(block(api() ?: throw NotPairedException()))
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            AccountResult.Failed(AccountProblem.of(e))
        }
    }

    suspend fun account(): AccountResult<AccountDto> = run { it.account() }

    suspend fun security(): AccountResult<AccountSecurityDto> = run { it.accountSecurity() }

    /** The server's password rules (`GET /api/auth/options`); null on an older server, and the server still decides. */
    suspend fun passwordRules(): PasswordRulesDto? = run { it.authOptions().passwordRules }.valueOrNull

    suspend fun sendProofCode(): AccountResult<Unit> = run { it.sendProofCode() }

    suspend fun rename(name: String): AccountResult<AccountDto> = run { it.updateName(name) }

    suspend fun uploadAvatar(jpeg: ByteArray): AccountResult<AvatarDto> = run { it.uploadAvatar(jpeg) }

    suspend fun removeAvatar(): AccountResult<Unit> = run { it.removeAvatar() }

    suspend fun startEmailChange(email: String, proof: Proof): AccountResult<PendingEmailDto> = run { it.startEmailChange(email, proof) }

    suspend fun confirmEmailChange(code: String): AccountResult<AccountDto> = run { it.confirmEmailChange(code) }

    suspend fun cancelEmailChange(): AccountResult<Unit> = run { it.cancelEmailChange() }

    suspend fun changePassword(current: String?, new: String, proof: Proof?, signOutOthers: Boolean): AccountResult<Unit> =
        run { it.changePassword(current, new, proof, signOutOthers) }

    suspend fun startTwoStep(proof: Proof): AccountResult<TwoFactorSetupDto> = run { it.startTwoFactor(proof) }

    suspend fun confirmTwoStep(ceremony: String, code: String): AccountResult<RecoveryCodesDto> = run {
        it.confirmTwoFactor(ceremony, code)
    }

    suspend fun newRecoveryCodes(otp: String): AccountResult<RecoveryCodesDto> = run { it.newRecoveryCodes(otp) }

    suspend fun turnOffTwoStep(otp: String): AccountResult<Unit> = run { it.turnOffTwoFactor(otp) }

    suspend fun unlinkGoogle(proof: Proof): AccountResult<Unit> = run { it.unlinkGoogle(proof) }

    suspend fun passkeyOptions(proof: Proof): AccountResult<PasskeyOptionsDto> = run { it.passkeyOptions(proof) }

    suspend fun savePasskey(ceremony: String, name: String, credential: JsonObject): AccountResult<AddedPasskeyDto> =
        run { it.savePasskey(ceremony, name, credential) }

    suspend fun renamePasskey(id: String, name: String): AccountResult<Unit> = run { it.renamePasskey(id, name) }

    suspend fun removePasskey(id: String, proof: Proof): AccountResult<Unit> = run { it.removePasskey(id, proof) }

    suspend fun renameDevice(id: String, name: String): AccountResult<Unit> = run { it.renameAccountDevice(id, name) }

    suspend fun signOutDevice(id: String): AccountResult<Unit> = run { it.signOutAccountDevice(id) }

    suspend fun signOutBrowser(id: String): AccountResult<Unit> = run { it.signOutBrowser(id) }

    suspend fun avatarImage(url: String): ByteArray? = run { it.avatarImage(url) }.valueOrNull
}
