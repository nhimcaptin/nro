package Deputyhead.Service;
import Deputyhead.TreasureUnderSea;
import item.Item;
import java.util.ArrayList;
import java.util.List;
import map.Zone;
import map.Service.ChangeMapService;
import player.Player;
import player.Service.InventoryService;
import services.Service;
import utils.Util;

public class TreasureUnderSeaService {

    private static TreasureUnderSeaService instance;

    public static TreasureUnderSeaService gI() {
        if (instance == null) {
            instance = new TreasureUnderSeaService();
        }
        return instance;
    }

    public List<TreasureUnderSea> banDoKhoBaus;

    private TreasureUnderSeaService() {
        this.banDoKhoBaus = new ArrayList<>();
        for (int i = 0; i < TreasureUnderSea.AVAILABLE; i++) {
            this.banDoKhoBaus.add(new TreasureUnderSea(i));
        }
    }

    public void addMapBanDoKhoBau(int id, Zone zone) {
        this.banDoKhoBaus.get(id).getZones().add(zone);
    }

    public Zone getMapJoin(Player player) {
        if (player == null || player.clan == null || player.clan.BanDoKhoBau == null) {
            return null;
        }
        TreasureUnderSea bdkb = player.clan.BanDoKhoBau;
        for (Zone zone : bdkb.getZones()) {
            if (zone == null) {
                continue;
            }
            for (Player pl : zone.getPlayers()) {
                if (pl != null && pl.isPl() && pl != player) {
                    return zone;
                }
            }
        }
        return bdkb.getMapById(135);
    }

    public void joinBanDoKhoBau(Player player) {
        Zone zoneJoin = getMapJoin(player);
        if (zoneJoin == null) {
            Service.gI().sendThongBao(player, "Không thể vào hang kho báu");
            return;
        }
        int x = 100 + Util.nextInt(-20, 20);
        int y = zoneJoin.map.yPhysicInTop(x, 100);
        if (y <= 24) {
            y = 336;
        }
        ChangeMapService.gI().changeMap(player, zoneJoin, x, y);
    }

    public void openBanDoKhoBau(Player player, byte level) {
        if (level >= 1 && level <= 110) {
            if (player.clan != null && player.clan.BanDoKhoBau == null) {
                Item item = InventoryService.gI().findItemBag(player, 611);
                if (item != null && item.quantity > 0) {
                    TreasureUnderSea banDoKhoBau = null;
                    for (TreasureUnderSea bdkb : this.banDoKhoBaus) {
                        if (!bdkb.isOpened) {
                            banDoKhoBau = bdkb;
                            break;
                        }
                    }
                    if (banDoKhoBau != null) {
                        if (Util.isAfterMidnight(player.lastTimeJoinBDKB)) {
                            player.timesPerDayBDKB = 1;
                        } else if (player.lastTimeJoinBDKB != player.clan.lastTimeOpenBanDoKhoBau) {
                            if (player.timesPerDayBDKB >= 3) {
                                Service.gI().sendThongBao(player, "Bạn đã vào hang kho báu 3 lần trong hôm nay, hẹn gặp lại ngày mai");
                                return;
                            }
                        }

                        InventoryService.gI().subQuantityItemsBag(player, item, 1);
                        InventoryService.gI().sendItemBags(player);
                        banDoKhoBau.openBanDoKhoBau(player, player.clan, level);
                    } else {
                        Service.gI().sendThongBao(player, "Hang kho báu đã đầy, hãy quay lại sau 30 phút");
                    }
                } else {
                    Service.gI().sendThongBao(player, "Không tìm thấy bản đồ kho báu");
                }
            } else {
                Service.gI().sendThongBao(player, "Không thể thực hiện");
            }
        }
    }
}
