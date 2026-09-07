package managers;

import database.DatabaseManager;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.util.HashMap;
import java.util.Map;
import utils.Logger;

public class PotentialReductionManager implements Runnable {

    private static final long RELOAD_INTERVAL = 10 * 60 * 1000;

    private static PotentialReductionManager instance;

    private volatile Map<String, Double> reductionByUsername = new HashMap<>();

    public static PotentialReductionManager gI() {
        if (instance == null) {
            instance = new PotentialReductionManager();
        }
        return instance;
    }

    public void load() {
        Map<String, Double> map = new HashMap<>();
        try (Connection con = DatabaseManager.getConnection();
                PreparedStatement ps = con.prepareStatement("select username, reduction_rate from potential_reduction_users")) {
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    String username = rs.getString("username");
                    if (username == null) {
                        continue;
                    }
                    double rate = rs.getDouble("reduction_rate");
                    if (rate < 0) {
                        rate = 0;
                    } else if (rate > 1) {
                        rate = 1;
                    }
                    map.put(username.toLowerCase(), rate);
                }
            }
            this.reductionByUsername = map;
        } catch (Exception e) {
            Logger.log(Logger.RED, "Lỗi load potential_reduction_users: " + e.getMessage() + "\n");
        }
    }

    public double getRate(String username) {
        if (username == null) {
            return 0;
        }
        Double rate = this.reductionByUsername.get(username.toLowerCase());
        return rate == null ? 0 : rate;
    }

    public long applyReduction(String username, long tiemNang) {
        double rate = getRate(username);
        if (rate <= 0 || tiemNang <= 0) {
            return tiemNang;
        }
        return (long) (tiemNang * (1 - rate));
    }

    @Override
    public void run() {
        while (true) {
            load();
            try {
                Thread.sleep(RELOAD_INTERVAL);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return;
            }
        }
    }
}
