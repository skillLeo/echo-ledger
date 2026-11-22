package com.echoledger.mobile.ui.v2.settings

import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.core.PlanFormat
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.FlowStep
import com.echoledger.mobile.ui.manualPayments
import com.echoledger.mobile.ui.nameOf
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow

private const val ENDS_SOON = 7L

/** What the line under the profile card says and opens; null while nothing needs the person (classic's StateRow). */
data class StateLineWords(val icon: ImageVector, val text: String, val open: StateLineOpens)

sealed interface StateLineOpens {
    data object Plan : StateLineOpens
    data object Key : StateLineOpens
    data class Request(val step: FlowStep) : StateLineOpens
}

@Composable
fun stateLine(settings: AppSettings, deletesAt: String?, canPay: Boolean = manualPayments): StateLineWords? {
    val plan = settings.plan
    val r = plan.upgrade
    val name = plan.nameOf(r?.plan)
    val ends = PlanFormat.daysUntil(plan.expiresAt)
    return when {
        deletesAt != null -> StateLineWords(
            EchoIcons.Error,
            stringResource(R.string.v2_st_deletes, PlanFormat.shortDate(deletesAt) ?: deletesAt.take(10)),
            StateLineOpens.Plan
        )

        canPay && r?.stage == "needs_receipt" -> StateLineWords(
            EchoIcons.Error,
            stringResource(R.string.v2_st_receipt, name),
            StateLineOpens.Request(FlowStep.Receipt)
        )

        canPay && r?.stage == "awaiting_payment" -> StateLineWords(
            EchoIcons.Plan,
            stringResource(R.string.v2_st_waiting, name),
            StateLineOpens.Request(FlowStep.Pay)
        )

        canPay && r?.stage == "in_review" -> StateLineWords(
            EchoIcons.Plan,
            stringResource(R.string.v2_st_checking, name),
            StateLineOpens.Request(FlowStep.Status)
        )

        !plan.isFree && ends != null && ends in 0..ENDS_SOON -> StateLineWords(
            EchoIcons.Bell,
            stringResource(R.string.v2_st_ends, plan.displayName, PlanFormat.shortDate(plan.expiresAt).orEmpty()),
            StateLineOpens.Plan
        )

        settings.serverUrl != null && plan.needsOwnKey && plan.transcriptionKey?.set != true -> StateLineWords(
            EchoIcons.Key,
            stringResource(R.string.v2_st_key),
            StateLineOpens.Key
        )

        else -> null
    }
}

/** One line under the card while something needs the person: a tap opens what it is about. */
@Composable
fun StateLine(settings: AppSettings, deletesAt: String?, onOpenPlan: () -> Unit, onOpenKey: () -> Unit, onRequest: (FlowStep) -> Unit) {
    val line = stateLine(settings, deletesAt) ?: return
    EchoSettingRow(line.text, icon = line.icon, onClick = {
        when (val o = line.open) {
            StateLineOpens.Plan -> onOpenPlan()
            StateLineOpens.Key -> onOpenKey()
            is StateLineOpens.Request -> onRequest(o.step)
        }
    })
}
