package server;

import java.time.LocalDate;
import java.time.LocalDateTime;
import utils.Logger;

public class AutoMaintenance extends Thread {

    public static boolean ENABLED = true;
    public static int HOUR = 5;
    public static int MINUTE = 0;
    public static int COUNTDOWN_SECONDS = 900;

    private static AutoMaintenance instance;
    private LocalDate lastTriggeredDate;

    private AutoMaintenance() {
        super("AutoMaintenance");
    }

    public static AutoMaintenance gI() {
        if (instance == null) {
            instance = new AutoMaintenance();
        }
        return instance;
    }

    public static void loadConfig(Object enabled, Object hour, Object minute, Object countdown) {
        if (enabled != null) {
            ENABLED = String.valueOf(enabled).equalsIgnoreCase("true");
        }
        if (hour != null) {
            HOUR = Integer.parseInt(String.valueOf(hour));
        }
        if (minute != null) {
            MINUTE = Integer.parseInt(String.valueOf(minute));
        }
        if (countdown != null) {
            COUNTDOWN_SECONDS = Integer.parseInt(String.valueOf(countdown));
        }
    }

    @Override
    public void run() {
        while (ServerManager.isRunning) {
            try {
                checkSchedule();
                Thread.sleep(15_000);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                break;
            } catch (Exception e) {
                Logger.logException(AutoMaintenance.class, e);
            }
        }
    }

    private void checkSchedule() {
        if (!ENABLED || Maintenance.isRunning) {
            return;
        }

        LocalDateTime now = LocalDateTime.now();
        LocalDate today = now.toLocalDate();
        if (now.getHour() != HOUR || now.getMinute() != MINUTE) {
            return;
        }
        if (today.equals(lastTriggeredDate)) {
            return;
        }

        lastTriggeredDate = today;
        Logger.log(Logger.YELLOW, "AUTO MAINTENANCE AT "
                + String.format("%02d:%02d", HOUR, MINUTE)
                + " - COUNTDOWN " + COUNTDOWN_SECONDS + "s\n");
        Maintenance.gI().startNew(COUNTDOWN_SECONDS);
    }

    public static String getScheduleText() {
        if (!ENABLED) {
            return "Tắt";
        }
        int minutes = COUNTDOWN_SECONDS / 60;
        return String.format("%02d:%02d hàng ngày (countdown %d phút)", HOUR, MINUTE, minutes);
    }
}
