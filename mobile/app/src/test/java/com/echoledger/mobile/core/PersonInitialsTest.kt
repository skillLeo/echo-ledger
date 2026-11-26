package com.echoledger.mobile.core

import com.echoledger.mobile.ui.v2.shell.initialsOf
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * P-126: one initials rule on the phone, the web's (InitialsAvatarProvider::initials and PersonAvatar.php), so a person
 * shows the same letters on the account card, a contact's row, an Activity item and the website.
 */
class PersonInitialsTest {
    @Test
    fun theFirstAndTheLastWordsFirstLetterInUpperCase() {
        assertEquals("FK", PersonInitials.of("Farah Khan"))
        assertEquals("SH", PersonInitials.of("Saif Ali Hassam"))
        assertEquals("A", PersonInitials.of("ali"))
        assertEquals("AS", PersonInitials.of("  Asad   Shah  "))
        // Any space between words, also a no-break space and a tab.
        assertEquals("AS", PersonInitials.of("Asad\u00A0Shah"))
        assertEquals("AS", PersonInitials.of("Asad\tShah"))
    }

    @Test
    fun leadingPunctuationIsSkippedAndAWordWithoutALetterGivesNone() {
        // "[Ops] Farah": the bracket never becomes an initial; the word is still a word.
        assertEquals("OF", PersonInitials.of("[Ops] Farah"))
        assertEquals("F", PersonInitials.of("[] Farah"))
        assertEquals("BT", PersonInitials.of("\"Bilal\" (Traders)"))
        assertEquals("1A", PersonInitials.of("1st Avenue"))
    }

    @Test
    fun noNameGivesNoneAndTheCallerShowsThePersonIcon() {
        assertEquals("", PersonInitials.of(null))
        assertEquals("", PersonInitials.of(""))
        assertEquals("", PersonInitials.of("   "))
        assertEquals("", PersonInitials.of("— ·"))
    }

    @Test
    fun twoUrduOrArabicLettersAreKeptApartByAZeroWidthNonJoiner() {
        assertEquals("ع\u200Cخ", PersonInitials.of("عائشہ خان"))
        assertEquals("ع\u200Cر", PersonInitials.of("علی رضا"))
        assertEquals("م\u200Cع", PersonInitials.of("محمد احمد علی"))
        assertEquals("ع", PersonInitials.of("عائشہ"))
        // A name in both scripts keeps them apart too, as the web does whenever one of the two is Arabic script.
        assertEquals("A\u200Cخ", PersonInitials.of("Ayesha خان"))
        assertEquals(PersonInitials.ZWNJ, "\u200C")
    }

    @Test
    fun aLetterOutsideTheBasicPlaneIsKeptWhole() {
        // U+1D400 MATHEMATICAL BOLD CAPITAL A: two UTF-16 units, one letter.
        assertEquals("\uD835\uDC00B", PersonInitials.of("\uD835\uDC00lpha Bravo"))
    }

    @Test
    fun theAccountScreensAndAContactsRowUseTheSameRule() {
        for (name in listOf("Farah Khan", "Saif Ali Hassam", "[Ops] Farah", "عائشہ خان", "", null)) {
            assertEquals("$name", PersonInitials.of(name), initialsOf(name))
        }
    }
}
