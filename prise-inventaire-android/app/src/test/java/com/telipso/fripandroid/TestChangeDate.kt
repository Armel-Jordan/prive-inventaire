package com.telipso.fripandroid

import org.junit.Assert
import org.junit.Test
import java.time.Instant
import java.time.ZoneOffset
import java.time.ZonedDateTime

class TestChangeDate {

    fun updateInstantDate(unixEpochSeconds: Long, dateNew: Long): Instant {
        val offset = ZoneOffset.systemDefault()
        val originalInstant = Instant.ofEpochSecond(unixEpochSeconds)
        val originalZoned = originalInstant.atZone(offset)

        // Step 3: Replace the date (keep the original time)
        val updatedZoned = ZonedDateTime.of(
            Instant.ofEpochSecond(dateNew).atZone(offset).toLocalDate(),
            originalZoned.toLocalTime(),
            offset
        )

        // Step 4: Convert back to Instant
        return updatedZoned.toInstant()
    }

    @Test
    fun testChangeDate() {
        val instant = updateInstantDate(1751287752L, 1748209848L)

//        System.out.println(ZoneId.systemDefault())
        Assert.assertEquals("2025-05-25", SQLUtil.yyyymmdd(instant))
        Assert.assertEquals("08:49:12", SQLUtil.humanTime(instant))
    }
}