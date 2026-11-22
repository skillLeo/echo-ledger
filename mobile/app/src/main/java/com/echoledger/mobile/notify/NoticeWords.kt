package com.echoledger.mobile.notify

import android.content.Context
import android.content.res.Resources
import com.echoledger.mobile.R
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.core.ReadyProblem

/**
 * The app's own words in what Android draws for it (notifications, their actions and channels) in the app language.
 * The app makes those lines in English in many places (the recording service, Live Translate, the Phone app import);
 * each is said through this table (strings_v2_notice.xml, whose English is word for word the app's), whole, by its
 * pattern ("Recording call with %1$s"), or part by part between " · ". In English nothing changes. Words the server
 * writes (the Activity feed's titles) are not the app's: they stay as the server sends them.
 */
object NoticeWords {
    /** Lines said whole or as a pattern; the English of each is read from the English resources. */
    private val ids = listOf(
        R.string.n_test, R.string.n_calls_not_recorded, R.string.n_only_voice, R.string.n_paused, R.string.n_resume, R.string.n_pause,
        R.string.n_stop, R.string.n_captions, R.string.n_undo_discard, R.string.n_discard, R.string.n_ready_whatsapp,
        R.string.n_capture_not_ready,
        R.string.n_armed_ask, R.string.n_armed_keep, R.string.n_start_shizuku, R.string.n_record_now, R.string.n_turn_off,
        R.string.n_save_question, R.string.n_keep, R.string.n_saved, R.string.n_call_saved, R.string.n_undo, R.string.n_discarded,
        R.string.n_call_discarded, R.string.n_not_ready_text, R.string.n_call_not_recorded_first, R.string.n_no_sound_first,
        R.string.n_reason, R.string.n_open_setup_restart, R.string.n_call_not_recorded, R.string.n_no_audio, R.string.n_to_check_text,
        R.string.n_to_check_title, R.string.n_imported_one, R.string.n_imported_many, R.string.n_imported_text,
        R.string.n_copy_waiting_text,
        R.string.n_copy_waiting_title, R.string.n_phone_not_recorded, R.string.n_ask_phone_text, R.string.n_ask_phone_title,
        R.string.n_record, R.string.n_call_with, R.string.n_auto_off_text, R.string.n_auto_off_title, R.string.n_shared_note,
        R.string.n_me, R.string.n_client, R.string.n_rec_phone_call, R.string.n_rec_call, R.string.n_voice_note, R.string.n_recording,
        R.string.n_rec_call_with, R.string.n_both_sides, R.string.n_auto, R.string.n_manual, R.string.n_one_track, R.string.n_voice_only,
        R.string.n_waiting_answer, R.string.n_starts_on_pickup, R.string.n_microphone, R.string.n_bookmark_one, R.string.n_bookmarks,
        R.string.n_bookmarked, R.string.n_bookmarks_full, R.string.n_not_hearing_for, R.string.n_not_hearing_for_dot,
        R.string.n_not_hearing, R.string.n_no_far_sound, R.string.n_game_translate, R.string.n_watch_translate, R.string.n_game_started,
        R.string.n_uploading, R.string.n_missed, R.string.n_missed_many, R.string.n_phone_call, R.string.n_lt_paused,
        R.string.n_lt_translating, R.string.n_lt_lock, R.string.n_lt_edit, R.string.n_lt_show, R.string.n_lt_hide, R.string.n_lt_saved_to,
        R.string.n_lt_overlay, R.string.n_lt_no_plan, R.string.n_lt_choose_games, R.string.n_lt_start_helper, R.string.n_lt_nothing_playing,
        R.string.n_lt_could_not_start, R.string.n_lt_not_set_up, R.string.n_lt_budget_used, R.string.n_lt_not_answering,
        // Live Translate's languages ("Translating to %1$s").
        R.string.v2_lt_lang_en, R.string.v2_lt_lang_ur, R.string.v2_lt_lang_ar, R.string.v2_lt_lang_hi, R.string.v2_lt_lang_fa,
        R.string.v2_lt_lang_tr, R.string.v2_lt_lang_ru, R.string.v2_lt_lang_es, R.string.v2_lt_lang_fr, R.string.v2_lt_lang_de,
        R.string.v2_lt_lang_pt, R.string.v2_lt_lang_id, R.string.v2_lt_lang_ja, R.string.v2_lt_lang_ko, R.string.v2_lt_lang_zh,
        // The app's own call words the after-call card's title is made of ("%1$s call" last: it would match "Recording call").
        R.string.n_app_call
    )

    private const val PARTS = " · "

    /** The patterns whose hole is the app's own words, not the person's: "Paused · Recording call", "Translating to Urdu". */
    private val appWordHoles = setOf(R.string.n_paused, R.string.n_lt_translating)
    private val hole = Regex("""%(\d)\$[sd]""")

    private class Line(val id: Int, val exact: String?, val pattern: Regex?, val numbers: Set<Int>)

    @Volatile private var lines: List<Line>? = null

    private fun lines(english: Resources): List<Line> = lines ?: ids.map { id ->
        val text = english.getString(id)
        if (!hole.containsMatchIn(text)) {
            Line(id, text, null, emptySet())
        } else {
            val regex = text.split(hole).joinToString("(.+?)") { Regex.escape(it) }
            val numbers = hole.findAll(text).filter { it.value.endsWith("d") }.map { it.groupValues[1].toInt() }.toSet()
            Line(id, null, Regex(regex), numbers)
        }
    }.also { lines = it }

    fun isUrdu(res: Resources): Boolean = res.configuration.locales[0]?.language == "ur"

    /** [english] in the app language (the person's, from [context]). */
    fun say(context: Context, english: String?): String = say(AppWords.resources(context), AppWords.resources(context, "en"), english)

    /** [english] in [res]'s language; [en] are the English resources. Lines this table doesn't know stay as they are. */
    fun say(res: Resources, en: Resources, english: String?): String {
        val text = english.orEmpty()
        if (text.isEmpty() || !isUrdu(res)) return text
        val known = lines(en)
        known.firstOrNull { it.exact == text }?.let { return res.getString(it.id) }
        for (line in known) {
            val m = line.pattern?.matchEntire(text) ?: continue
            // A line of several parts ("Both sides · auto · 2 bookmarks") is said part by part, not as one name.
            if (line.id !in appWordHoles && m.groupValues.drop(1).any { it.contains(PARTS) }) continue
            // A hole holds the person's own words (a contact's name, a title, a file) as they are; only the holes that
            // hold the app's own words (a recording's state, a language) are said in the app language too.
            val args: List<Any> = m.groupValues.drop(1).mapIndexed { i, v ->
                when {
                    i + 1 in line.numbers -> v.toIntOrNull() ?: v
                    line.id in appWordHoles -> say(res, en, v)
                    else -> v
                }
            }
            return res.getString(line.id, *args.toTypedArray())
        }
        return if (text.contains(PARTS)) text.split(PARTS).joinToString(PARTS) { say(res, en, it) } else text
    }

    /** Whether the table has words for [english] (tests: every line the app can post). */
    fun knows(en: Resources, english: String): Boolean {
        val known = lines(en)
        if (known.any { it.exact == english || it.pattern?.matchEntire(english) != null }) return true
        return english.contains(PARTS) && english.split(PARTS).all { knows(en, it) || it.matches(Regex("""[\d:]+""")) }
    }

    /** The Ready check's alert in the app language: Setup & health's words for its problem (title, why, fix). */
    fun readiness(context: Context, problem: ReadyProblem): Triple<String, String, String?> {
        val res = AppWords.resources(context)
        val english = Triple(
            "Calls are not being recorded: ${problem.title.replaceFirstChar { it.lowercase() }}",
            problem.detail,
            problem.fix?.label
        )
        if (!isUrdu(res)) return english
        val fail = com.echoledger.mobile.ui.v2.setup.HealthChecks.failWords(problem.key) ?: return english
        val why = com.echoledger.mobile.ui.v2.setup.HealthChecks.whyWords(problem.key)?.let { id ->
            if (problem.key == "storage") {
                res.getString(id, Regex("""\d+""").find(problem.detail)?.value?.toLongOrNull() ?: 0L)
            } else {
                res.getString(id)
            }
        } ?: problem.detail
        val fix = problem.fix?.let { res.getString(com.echoledger.mobile.ui.v2.setup.HealthChecks.fixLabel(it)) }
        return Triple(res.getString(R.string.n_calls_not_recorded, res.getString(fail)), why, fix)
    }

    /** A notification channel's name and line in the app language. */
    fun channel(res: Resources, category: NotifyCategory): Pair<String, String> = when (category) {
        NotifyCategory.RECORDING -> R.string.c_recording to R.string.c_recording_d
        NotifyCategory.TRANSCRIPTION -> R.string.c_transcription to R.string.c_transcription_d
        NotifyCategory.ENHANCEMENT -> R.string.c_enhancement to R.string.c_enhancement_d
        NotifyCategory.UPLOADS -> R.string.c_uploads to R.string.c_uploads_d
        NotifyCategory.READINESS -> R.string.c_readiness to R.string.c_readiness_d
        NotifyCategory.SHARING -> R.string.c_sharing to R.string.c_sharing_d
        NotifyCategory.ACCOUNT -> R.string.c_account to R.string.c_account_d
        NotifyCategory.USAGE -> R.string.c_usage to R.string.c_usage_d
        NotifyCategory.LIVE_TRANSLATE -> R.string.c_live_translate to R.string.c_live_translate_d
        NotifyCategory.UPDATES -> R.string.c_updates to R.string.c_updates_d
        NotifyCategory.OTHER -> R.string.c_other to R.string.c_other_d
    }.let { (title, line) -> res.getString(title) to res.getString(line) }

    /** A channel's test notification (Settings › Notifications › Test) in the app language. */
    fun test(res: Resources, category: NotifyCategory): Pair<String, String> = when (category) {
        NotifyCategory.RECORDING -> R.string.c_recording_t to R.string.c_recording_b
        NotifyCategory.TRANSCRIPTION -> R.string.c_transcription_t to R.string.c_transcription_b
        NotifyCategory.ENHANCEMENT -> R.string.c_enhancement_t to R.string.c_enhancement_b
        NotifyCategory.UPLOADS -> R.string.c_uploads_t to R.string.c_uploads_b
        NotifyCategory.READINESS -> R.string.c_readiness_t to R.string.c_readiness_b
        NotifyCategory.SHARING -> R.string.c_sharing_t to R.string.c_sharing_b
        NotifyCategory.ACCOUNT -> R.string.c_account_t to R.string.c_account_b
        NotifyCategory.USAGE -> R.string.c_usage_t to R.string.c_usage_b
        NotifyCategory.LIVE_TRANSLATE -> R.string.c_live_translate_t to R.string.c_live_translate_b
        NotifyCategory.UPDATES -> R.string.c_updates_t to R.string.c_updates_b
        NotifyCategory.OTHER -> R.string.c_other_t to R.string.c_other_b
    }.let { (title, line) -> res.getString(title) to res.getString(line) }
}
