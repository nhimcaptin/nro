package server;

import utils.Util;

/**
 * Tỷ lệ rơi đồ có thể chỉnh từ Server Panel.
 * Util.isTrue(num, den) = xác suất num/den.
 */
public final class DropRateConfig {

    private DropRateConfig() {
    }

    public static int RATE_EXP = 1;

    public static int NGOC_NUM = 1;
    public static int NGOC_DEN = 1_000_000;

    public static int SET_SKH_NUM = 1;
    public static int SET_SKH_DEN = 5_000;

    public static int ITEM_SKH_NUM = 1;
    public static int ITEM_SKH_DEN = 5_000;

    public static int DO_SAO_SKH_NUM = 1;
    public static int DO_SAO_SKH_DEN = 50_000;

    public static int MANH_DA_VUN_NUM = 1;
    public static int MANH_DA_VUN_DEN = 1000;

    public static int GOLD_3_PLANETS_NUM = 1;
    public static int GOLD_3_PLANETS_DEN = 20;

    public static int GOLD_MAP_NUM = 1;
    public static int GOLD_MAP_DEN = 100;

    public static int NGOC_RONG_NUM = 1;
    public static int NGOC_RONG_DEN = 100;

    public static int DO_TL_COLD_NUM = 1;
    public static int DO_TL_COLD_DEN = 50_000;

    public static int FARM_NGOC_NUM = 50;
    public static int FARM_NGOC_DEN = 100;

    public static int FARM_THOI_VANG_NUM = 5;
    public static int FARM_THOI_VANG_DEN = 100;

    public static int BOSS_REWARD = 10;

    public static boolean roll(int num, int den) {
        if (num <= 0 || den <= 0) {
            return false;
        }
        return Util.isTrue(num, den);
    }
}
