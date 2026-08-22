package npc.list;

import consts.ConstNpc;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import npc.Npc;
import player.Player;
import shop.ShopService;

public class Santa extends Npc {

    public Santa(int mapId, int status, int cx, int cy, int tempId, int avartar) {
        super(mapId, status, cx, cy, tempId, avartar);
    }

    @Override
    public void openBaseMenu(Player player) {
        if (canOpenNpc(player)) {
            List<String> menu = new ArrayList<>(Arrays.asList(
                    "Cửa hàng",
                    "Mở rộng\nHành trang\nRương đồ",
                    // "Nhập mã\nquà tặng",
                    "Cửa hàng\nHạn sử dụng",
                    "Tiệm\nHớt tóc",
                    // "Danh\nhiệu",
                    "Shop Vip"
            ));

            // if (soLuong >= 1) {
            //     menu.add(1, "Giảm giá\n80%");
            // }

            String[] menus = menu.toArray(new String[0]);

            createOtherMenu(player, ConstNpc.BASE_MENU,
                    "Xin chào, ta có một số vật phẩm đặc biệt cậu có muốn xem không?", menus);
        }

    }

    @Override
    public void confirmMenu(Player player, int select) {
        if (canOpenNpc(player)) {
            if (this.mapId == 5 || this.mapId == 13 || this.mapId == 20) {
                if (player.idMark.isBaseMenu()) {
                    switch (select) {
                        case 0:
                            ShopService.gI().opendShop(player, "SANTA", false);
                            break;
                        case 1:
                            ShopService.gI().opendShop(player, "SANTA_MO_RONG_HANH_TRANG", false);
                            break;
                        case 2:
                            ShopService.gI().opendShop(player, "SANTA_HAN_SU_DUNG", false);
                            break;
                        case 3:
                            ShopService.gI().opendShop(player, "SANTA_HEAD", false);
                            break;
                        case 4:
                            ShopService.gI().opendShop(player, "SHOP_VIP", false);
                            break;
                    }
                }
            }
        }
    }
}
