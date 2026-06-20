package boss.BossManager;

import boss.Boss;
import consts.BossStatus;
import java.util.ArrayList;
import java.util.List;

public final class BossRegistry {

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
                .toList();
    }
}
