package boss.BossManager;

import boss.Boss;
import boss.BossID;
import boss.Yardat.Yardart;
import consts.BossStatus;
import java.util.ArrayList;
import java.util.List;
import java.util.Set;
import map.Service.MapService;

public final class BossRegistry {

    private static final Set<Integer> HIDDEN_PANEL_MAP_IDS = Set.of(
            114, // Cổng phi thuyền
            115, // Phòng chờ
            117, // Cửa ải 1
            118, // Cửa ải 2
            119, // Cửa ải 3
            120, // Phòng chỉ huy
            127  // Cổng phi thuyền (Namek)
    );

    private static final Set<Integer> HIDDEN_PANEL_BOSS_IDS = Set.of(
            BossID.BROLY_BASE,
            BossID.GOKU,
            BossID.CADIC,
            BossID.TAU_PAY_PAY,
            BossID.TAU_PAY_PAY_DONG_NAM_KARIN,
            BossID.TAUPAYPAY,
            BossID.TAU_PAIPAI
    );

    private BossRegistry() {
    }

    public static List<Boss> getAllBosses() {
        List<Boss> all = new ArrayList<>();
        addFrom(all, BossManager.gI());
        addFrom(all, YardartManager.gI());
        addFrom(all, FinalBossManager.gI());
        addFrom(all, SkillSummonedManager.gI());
        addFrom(all, BrolyManager.gI());
        addFrom(all, OtherBossManager.gI());
        addFrom(all, RedRibbonHQManager.gI());
        addFrom(all, TreasureUnderSeaManager.gI());
        addFrom(all, SnakeWayManager.gI());
        addFrom(all, GasDestroyManager.gI());
        return all;
    }

    private static void addFrom(List<Boss> target, BossManager manager) {
        if (manager != null) {
            target.addAll(manager.getBosses());
        }
    }

    public static List<Boss> getAliveBosses() {
        return getAllBosses().stream()
                .filter(boss -> !boss.isDie() && boss.bossStatus != null && boss.bossStatus != BossStatus.DIE)
                .filter(BossRegistry::shouldShowInPanel)
                .toList();
    }

    public static boolean shouldShowInPanel(Boss boss) {
        if (boss instanceof Yardart) {
            return false;
        }

        if (HIDDEN_PANEL_BOSS_IDS.contains(boss.id)) {
            return false;
        }

        if (boss.data != null && boss.data.length > 0) {
            String name = boss.data[0].getName();
            if (isHiddenBossName(name)) {
                return false;
            }
        }

        if (boss.zone != null && isHiddenPanelMap(boss.zone.map.mapId)) {
            return false;
        }

        if (boss.data != null && boss.data.length > 0) {
            for (int mapId : boss.data[0].getMapJoin()) {
                if (isHiddenPanelMap(mapId) || MapService.gI().isMapYardart(mapId)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static boolean isHiddenPanelMap(int mapId) {
        return HIDDEN_PANEL_MAP_IDS.contains(mapId) || MapService.gI().isMapYardart(mapId);
    }

    private static boolean isHiddenBossName(String name) {
        if (name == null || name.isBlank()) {
            return false;
        }
        String normalized = name.trim().toLowerCase();
        return normalized.equals("broly base")
                || normalized.equals("gôcu")
                || normalized.equals("ca đít")
                || normalized.equals("ca dit")
                || normalized.contains("tàu pảy pảy")
                || normalized.contains("tau pay pay");
    }
}
