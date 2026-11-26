package com.echoledger.mobile.ui.v2.settings

import android.content.res.Resources
import com.echoledger.mobile.R
import com.echoledger.mobile.core.CheckFix
import com.echoledger.mobile.core.CheckRow
import com.echoledger.mobile.core.CheckState
import com.echoledger.mobile.core.DeviceCheck
import com.echoledger.mobile.core.PhoneBrand
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.privilege.Capabilities

/**
 * The device check's words in the screen's language. The rows themselves stay as [DeviceCheck] makes them (their
 * English goes to the server and into Copy report as they are, docs/API.md); each is shown through this table, by its
 * key and its English line, so a line [DeviceCheck] can say and this table can't is caught by PhoneCheckParityTest.
 */
object PhoneCheckWords {
    private val details: Map<String, Int> by lazy {
        mapOf(
            "Voice notes and file imports work." to R.string.v2_pc_d_vn_ok,
            "Importing files works. Allow the microphone to record voice notes." to R.string.v2_pc_d_vn_mic,
            "WhatsApp, Meet and other app calls record both sides." to R.string.v2_pc_d_calls_both,
            DeviceCheck.callLine(AppCallCapture.NEEDS_HELPER)!! to R.string.v2_pc_d_calls_needs_helper,
            DeviceCheck.callLine(AppCallCapture.VOICE_ONLY)!! to R.string.v2_pc_d_calls_voice_only,
            DeviceCheck.callLine(AppCallCapture.VOICE_ONLY_NEEDS_HELPER)!! to R.string.v2_pc_d_calls_voice_only_helper,
            DeviceCheck.callLine(AppCallCapture.NONE)!! to R.string.v2_pc_d_calls_none,
            Capabilities.NOT_IN_THIS_BUILD to R.string.v2_pc_d_not_in_build,
            "This device has no phone line." to R.string.v2_pc_d_sim_no_line,
            "Recording SIM calls needs Android 12 or newer." to R.string.v2_pc_d_sim_android,
            "SIM calls record through the capture helper." to R.string.v2_pc_d_sim_helper,
            "Turn it on under Calls & meetings. Some phone makers block the phone line: if so, the app says it after 8 seconds." to
                R.string.v2_pc_d_sim_on,
            "Live captions work once you are signed in." to R.string.v2_pc_d_cap_sign_in,
            "Allow the microphone to see captions of what you say." to R.string.v2_pc_d_cap_mic,
            "Captions show while you record; they need the internet." to R.string.v2_pc_d_cap_ok,
            PlanState.NO_LIVE_TRANSLATE to R.string.v2_pc_d_lt_plan,
            "Needs Display over other apps for its box." to R.string.v2_pc_d_lt_overlay,
            "Films, apps and game voice chat." to R.string.v2_pc_d_lt_ok,
            "Films and apps work, and Android asks each time. The capture helper adds game voice chat." to R.string.v2_pc_d_lt_helper,
            "Films and apps work, and Android asks each time. Game voice chat needs Android 14." to R.string.v2_pc_d_lt_android
        )
    }

    private val titles = mapOf(
        "voice_notes" to R.string.v2_pc_t_voice_notes,
        "app_calls" to R.string.v2_pc_t_app_calls,
        "sim_calls" to R.string.v2_pc_t_sim_calls,
        "captions" to R.string.v2_pc_t_captions,
        "live_translate" to R.string.v2_pc_t_live_translate,
        "background" to R.string.v2_pc_t_background,
        "memory" to R.string.v2_pc_t_memory
    )

    fun title(res: Resources, row: CheckRow): String = titles[row.key]?.let(res::getString) ?: row.title

    fun state(res: Resources, state: CheckState): String = res.getString(
        when (state) {
            CheckState.OK -> R.string.v2_pc_state_ok
            CheckState.LIMITED -> R.string.v2_pc_state_limited
            CheckState.OFF -> R.string.v2_pc_state_off
        }
    )

    fun fix(res: Resources, fix: CheckFix): String = res.getString(
        if (fix ==
            CheckFix.HELPER
        ) {
            R.string.v2_pc_fix_set_up
        } else {
            R.string.v2_pc_fix_allow
        }
    )

    fun brand(res: Resources, brand: PhoneBrand): String = res.getString(
        when (brand) {
            PhoneBrand.SAMSUNG -> R.string.v2_pc_b_samsung
            PhoneBrand.XIAOMI -> R.string.v2_pc_b_xiaomi
            PhoneBrand.OPPO -> R.string.v2_pc_b_oppo
            PhoneBrand.ONEPLUS -> R.string.v2_pc_b_oneplus
            PhoneBrand.VIVO -> R.string.v2_pc_b_vivo
            PhoneBrand.HUAWEI -> R.string.v2_pc_b_huawei
            PhoneBrand.PIXEL -> R.string.v2_pc_b_pixel
            PhoneBrand.OTHER -> R.string.v2_pc_b_other
        }
    )

    /** The maker's own steps, in the same order as [PhoneBrand.backgroundSteps]. */
    fun steps(res: Resources, brand: PhoneBrand): List<String> = when (brand) {
        PhoneBrand.SAMSUNG -> listOf(R.string.v2_pc_s_samsung_1, R.string.v2_pc_s_samsung_2)
        PhoneBrand.XIAOMI -> listOf(R.string.v2_pc_s_xiaomi_1, R.string.v2_pc_s_xiaomi_2, R.string.v2_pc_s_xiaomi_3)
        PhoneBrand.OPPO -> listOf(R.string.v2_pc_s_oppo_1, R.string.v2_pc_s_oppo_2)
        PhoneBrand.ONEPLUS -> listOf(R.string.v2_pc_s_oppo_1, R.string.v2_pc_s_oneplus_2)
        PhoneBrand.VIVO -> listOf(R.string.v2_pc_s_vivo_1, R.string.v2_pc_s_vivo_2)
        PhoneBrand.HUAWEI -> listOf(R.string.v2_pc_s_huawei_1, R.string.v2_pc_s_huawei_2)
        PhoneBrand.PIXEL -> listOf(R.string.v2_pc_s_pixel_1)
        PhoneBrand.OTHER -> listOf(R.string.v2_pc_s_other_1)
    }.map(res::getString)

    /** The row's line in the screen's language; null when this table has no words for it (the test says so). */
    fun detailOrNull(res: Resources, row: CheckRow, brand: PhoneBrand, memoryGb: Int): String? = when (row.key) {
        "memory" -> res.getString(R.string.v2_pc_memory, memoryGb)

        "background" -> res.getString(
            if (row.state == CheckState.OK) R.string.v2_pc_d_bg_ok else R.string.v2_pc_d_bg_limited,
            brand(res, brand)
        )

        else -> details[row.detail]?.let(res::getString)
    }

    fun detail(res: Resources, row: CheckRow, brand: PhoneBrand, memoryGb: Int): String =
        detailOrNull(res, row, brand, memoryGb) ?: row.detail
}
