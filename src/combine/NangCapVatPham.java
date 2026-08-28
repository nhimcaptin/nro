package combine;

import consts.ConstNpc;
import item.Item;
import java.util.Objects;
import static combine.CombineService.MAX_LEVEL_ITEM;
import consts.ConstTaskBadges;
import player.Player;
import server.ServerNotify;
import services.ChatGlobalService;
import player.Service.InventoryService;
import services.Service;
import task.BadgesTaskService;
import utils.Util;

public class NangCapVatPham {
    public static final int MAX_LEVEL_COMBINE = 7;
    public static final double SUCCESS_RATE_INCREMENT = 10.0; 
    public static final int MIN_OPTION_INCREMENT = 1; 
    public static final String MESSAGE_NGOC_ROI_DO = "Chúc mừng %s vừa nâng cấp %s thành công lên +%d!";
    
    public static void showInfoCombine(Player player) {
        if (player.combineNew.itemsCombine.size() < 2 || player.combineNew.itemsCombine.size() > 3) {
            showError(player, "Cần trang bị và đá nâng cấp phù hợp");
            return;
        }

        Item itemDo = null;
        Item itemDNC = null;
        Item itemDBV = null;
        for (Item item : player.combineNew.itemsCombine) {
            if (!item.isNotNullItem()) {
                continue;
            }
            if (item.template.id == 987) {
                itemDBV = item;
            } else if (item.template.type < 5) {
                itemDo = itemDo == null ? item : null;
            } else if (item.template.type == 14) {
                itemDNC = itemDNC == null ? item : null;
            }
        }

        if (itemDo == null || itemDNC == null || !CombineSystem.isCoupleItemNangCapCheck(itemDo, itemDNC)) {
            showError(player, "Trang bị và đá nâng cấp không phù hợp");
            return;
        }
        if (player.combineNew.itemsCombine.size() == 3 && itemDBV == null) {
            showError(player, "Vật phẩm thứ ba phải là Đá bảo vệ");
            return;
        }

        int level = getLevel(itemDo);
        if (level >= MAX_LEVEL_COMBINE) {
            showError(player, "Trang bị đã đạt cấp tối đa +" + MAX_LEVEL_COMBINE);
            return;
        }

        player.combineNew.countDaNangCap = CombineSystem.getCountDaNangCapDo(level);
        player.combineNew.countDaBaoVe = (short) CombineSystem.getCountDaBaoVe(level);
        player.combineNew.goldCombine = CombineSystem.getGoldNangCapDo(level);
        player.combineNew.ratioCombine = (float) CombineSystem.getTileNangCapDo(level);

        StringBuilder npcSay = new StringBuilder();
        npcSay.append("|2|Nâng cấp ").append(itemDo.template.name)
                .append(" từ +").append(level).append(" lên +").append(level + 1).append("\n")
                .append("|2|Tỉ lệ thành công: ").append(player.combineNew.ratioCombine).append("%\n")
                .append("|2|Cần ").append(player.combineNew.countDaNangCap).append(" ")
                .append(itemDNC.template.name).append("\n")
                .append("|2|Cần ").append(Util.numberToMoney(player.combineNew.goldCombine)).append(" vàng");
        if (itemDBV != null) {
            npcSay.append("\n|2|Dùng ").append(player.combineNew.countDaBaoVe)
                    .append(" Đá bảo vệ khi thất bại");
        }
        if (itemDNC.quantity < player.combineNew.countDaNangCap) {
            npcSay.append("\n|7|Không đủ đá nâng cấp");
        }
        if (itemDBV != null && itemDBV.quantity < player.combineNew.countDaBaoVe) {
            npcSay.append("\n|7|Không đủ Đá bảo vệ");
        }
        if (player.inventory.gold < player.combineNew.goldCombine) {
            npcSay.append("\n|7|Còn thiếu ")
                    .append(Util.numberToMoney(player.combineNew.goldCombine - player.inventory.gold)).append(" vàng");
        }
        CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.MENU_START_COMBINE,
                npcSay.toString(), "Nâng cấp", "Từ chối");
    }

    private static int getLevel(Item item) {
        for (Item.ItemOption option : item.itemOptions) {
            if (option.optionTemplate.id == 72) {
                return option.param;
            }
        }
        return 0;
    }

    private static void showError(Player player, String message) {
        CombineService.gI().baHatMit.createOtherMenu(player, ConstNpc.IGNORE_MENU, message, "Đóng");
    }
    public static void nangCapVatPham(Player player) {
        if (player.combineNew.itemsCombine.size() >= 2 && player.combineNew.itemsCombine.size() < 4) {
            if (player.combineNew.itemsCombine.stream().filter(item -> item.isNotNullItem() && item.template.type < 5).count() != 1) {
                return;
            }
            if (player.combineNew.itemsCombine.stream().filter(item -> item.isNotNullItem() && item.template.type == 14).count() != 1) {
                return;
            }
            if (player.combineNew.itemsCombine.size() == 3 && player.combineNew.itemsCombine.stream().filter(item -> item.isNotNullItem() && item.template.id == 987).count() != 1) {
                return;
            }
            Item itemDo = null;
            Item itemDNC = null;
            Item itemDBV = null;
            for (int j = 0; j < player.combineNew.itemsCombine.size(); j++) {
                if (player.combineNew.itemsCombine.get(j).isNotNullItem()) {
                    if (player.combineNew.itemsCombine.size() == 3 && player.combineNew.itemsCombine.get(j).template.id == 987) {
                        itemDBV = player.combineNew.itemsCombine.get(j);
                        continue;
                    }
                    if (player.combineNew.itemsCombine.get(j).template.type < 5) {
                        itemDo = player.combineNew.itemsCombine.get(j);
                    } else {
                        itemDNC = player.combineNew.itemsCombine.get(j);
                    }
                }
            }
            if (CombineSystem.isCoupleItemNangCapCheck(itemDo, itemDNC)) {
                int countDaNangCap = player.combineNew.countDaNangCap;
                int gold = player.combineNew.goldCombine;
                short countDaBaoVe = player.combineNew.countDaBaoVe;
                if (player.inventory.gold < gold) {
                    Service.gI().sendThongBao(player, "Không đủ vàng để thực hiện");
                    return;
                }

                if (itemDNC.quantity < countDaNangCap) {
                    return;
                }
                if (player.combineNew.itemsCombine.size() == 3) {
                    if (Objects.isNull(itemDBV)) {
                        return;
                    }
                    if (itemDBV.quantity < countDaBaoVe) {
                        return;
                    }
                }

                int level = 0;
                Item.ItemOption optionLevel = null;
                for (Item.ItemOption io : itemDo.itemOptions) {
                    if (io.optionTemplate.id == 72) {
                        level = io.param;
                        optionLevel = io;
                        break;
                    }
                }
                if (level < MAX_LEVEL_COMBINE) {
                    player.inventory.gold -= gold;
                    Item.ItemOption option = null;
                    Item.ItemOption option2 = null;
                    for (Item.ItemOption io : itemDo.itemOptions) {
                        if (io.optionTemplate.id == 47
                                || io.optionTemplate.id == 6
                                || io.optionTemplate.id == 0
                                || io.optionTemplate.id == 7
                                || io.optionTemplate.id == 14
                                || io.optionTemplate.id == 22
                                || io.optionTemplate.id == 23) {
                            option = io;
                        } else if (io.optionTemplate.id == 27
                                || io.optionTemplate.id == 28) {
                            option2 = io;
                        }
                    }
                    if (Util.isTrue(player.combineNew.ratioCombine, 100)) {
                        option.param += (option.param * SUCCESS_RATE_INCREMENT / 100) < MIN_OPTION_INCREMENT ? MIN_OPTION_INCREMENT : (option.param * SUCCESS_RATE_INCREMENT / 100);
                        if (option2 != null) {
                            option2.param += (option2.param * SUCCESS_RATE_INCREMENT / 100) < MIN_OPTION_INCREMENT ? MIN_OPTION_INCREMENT : (option2.param * SUCCESS_RATE_INCREMENT / 100);
                        }
                        if (optionLevel == null) {
                            itemDo.itemOptions.add(new Item.ItemOption(72, 1));
                        } else {
                            optionLevel.param++;
                        }
                        if (optionLevel != null && optionLevel.param >= 5) {
                            ChatGlobalService.gI().ThongBaoRoiDo(player, String.format(MESSAGE_NGOC_ROI_DO, player.name, itemDo.template.name, optionLevel.param));
                        }
                        CombineService.gI().sendEffectSuccessCombine(player);
                        if (level == 7) {
                            BadgesTaskService.updateCountBagesTask(player, ConstTaskBadges.THANH_DAP_DO_7, 1);
                        }
                    } else {
                        if ((level == 2 || level == 4 || level == 6) && (player.combineNew.itemsCombine.size() != 3)) {
                            option.param -= (option.param * 11 / 100) < MIN_OPTION_INCREMENT ? MIN_OPTION_INCREMENT : (option.param * 11 / 100);
                            if (option2 != null) {
                                option2.param -= (option2.param * 11 / 100) < MIN_OPTION_INCREMENT ? MIN_OPTION_INCREMENT : (option2.param * 11 / 100);
                            }
                            optionLevel.param--;
                        }
                        CombineService.gI().sendEffectFailCombine(player);
                    }
                    if (player.combineNew.itemsCombine.size() == 3) {
                        InventoryService.gI().subQuantityItemsBag(player, itemDBV, countDaBaoVe);
                    }
                    InventoryService.gI().subQuantityItemsBag(player, itemDNC, player.combineNew.countDaNangCap);
                    InventoryService.gI().sendItemBags(player);
                    Service.gI().sendMoney(player);
                    CombineService.gI().reOpenItemCombine(player);
                    player.combineNew.itemsCombine.clear();
                }
            }
        }
    }

}