package boss.Nappa;
import boss.Boss;
import boss.BossID;
import consts.BossStatus;
import boss.BossesData;
import java.util.Random;
import map.ItemMap;
import player.Player;
import services.Service;
import services.TaskService;
import utils.Util;

public class MapDauDinh extends Boss {

    private long st;

    public MapDauDinh() throws Exception {
        super(BossID.MAP_DAU_DINH, true, true, BossesData.MAP_DAU_DINH);
    }

    @Override
    public void joinMap() {
        super.joinMap();
        st = System.currentTimeMillis();
    }

    @Override
    public void reward(Player plKill) {
        TaskService.gI().checkDoneTaskKillBoss(plKill, this);
        Service.gI().dropItemMap(this.zone, new ItemMap(this.zone, 190, Util.nextInt(20000, 30001),
          this.location.x, this.zone.map.yPhysicInTop(this.location.x, this.location.y - 24), plKill.id));
        if (Util.isTrue(80, 100)) {
            int[] items = Util.isTrue(50, 100) ? new int[]{18, 19, 20} : new int[]{18,19,20};
            int randomItem = items[new Random().nextInt(items.length)];
            Service.gI().dropItemMap(this.zone, new ItemMap(this.zone, randomItem, 1,
          this.location.x, this.zone.map.yPhysicInTop(this.location.x, this.location.y - 24), plKill.id));
        }
    }    @Override
    public void autoLeaveMap() {
        if (Util.canDoWithTime(st, 900000)) {
            this.changeStatus(BossStatus.LEAVE_MAP);
        }
    }
}
