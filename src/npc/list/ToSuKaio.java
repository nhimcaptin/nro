package npc.list;

/*
 * @Author Coder: Nguyễn Tấn Tài
 * @Description: Ngọc Rồng Kiwi - Máy Chủ Chuẩn Teamobi 2025
 * @Group Zalo: https://zalo.me/g/toiyeuvietnam2025
 */

import boss.BossID;
import consts.ConstNpc;
import Deputyhead.Service.TrainingService;
import npc.Npc;
import player.NPoint;
import player.Player;
import map.Service.NpcService;
import services.OpenPowerService;
import services.Service;
import utils.Util;

public class ToSuKaio extends Npc {

    private static final int OPEN_POWER_TARGET_MENU = 2002;
    private static final byte MIN_LIMIT_POWER = 8;

    public ToSuKaio(int mapId, int status, int cx, int cy, int tempId, int avartar) {
        super(mapId, status, cx, cy, tempId, avartar);
    }

    @Override
    public void openBaseMenu(Player player) {
        if (canOpenNpc(player)) {
            String message = String.format("Tập luyện với Tổ sư Kaio sẽ tăng %s sức mạnh mỗi phút, có thể tăng giảm tùy vào khả năng đánh quái của con",
                    Util.formatNumber(TrainingService.gI().getTnsmMoiPhut(player)));
            String autoTrainingOption = player.dangKyTapTuDong ? "Hủy đăng ký tập tự động" : "Đăng ký tập tự động";
            String autoTrainingMessage = player.dangKyTapTuDong ? "Hủy đăng\nký tập\ntự động" : "Đăng ký\ntập\ntự động";

            this.createOtherMenu(player, ConstNpc.BASE_MENU, message,
                    autoTrainingMessage, "Đồng ý\nluyện tập", "Không\nđồng ý", "Nâng\nGiới hạn\nSức mạnh");
        }
    }

    @Override
    public void confirmMenu(Player player, int select) {
        if (!canOpenNpc(player)) return;

        if (player.idMark.isBaseMenu()) {
            switch (select) {
                case 0:
                    handleAutoTrainingMenu(player);
                    break;
                case 1:
                    TrainingService.gI().callBoss(player, BossID.TO_SU_KAIO, false);
                    break;
                case 3:
                    showOpenPowerTargetMenu(player);
                    break;
                default:
                    break;
            }
        } else if (player.idMark.getIndexMenu() == 2001) {
            handleAutoTrainingRegistration(player, select);
        } else if (player.idMark.getIndexMenu() == ConstNpc.OPEN_POWER_MYSEFT) {
            handleOpenPower(player, player, select, false);
        } else if (player.idMark.getIndexMenu() == ConstNpc.OPEN_POWER_PET) {
            if (player.pet != null) {
                handleOpenPower(player, player.pet, select, true);
            }
        } else if (player.idMark.getIndexMenu() == OPEN_POWER_TARGET_MENU) {
            switch (select) {
                case 0:
                    showOpenPowerMenu(player, player, false);
                    break;
                case 1:
                    if (player.pet == null) {
                        Service.gI().sendThongBao(player, "Không thể thực hiện");
                        return;
                    }
                    showOpenPowerMenu(player, player.pet, true);
                    break;
                default:
                    break;
            }
        }
    }

    private void showOpenPowerTargetMenu(Player player) {
        this.createOtherMenu(player, OPEN_POWER_TARGET_MENU,
                "Con muốn nâng giới hạn sức mạnh cho bản thân hay đệ tử?",
                "Bản thân", "Đệ tử", "Đóng");
    }

    private void showOpenPowerMenu(Player owner, Player target, boolean isPet) {
        if (target.nPoint.limitPower < MIN_LIMIT_POWER) {
            this.createOtherMenu(owner, ConstNpc.IGNORE_MENU,
                    isPet ? "Sức mạnh của đệ tử chưa đạt 80 tỷ"
                            : "Con chưa đạt 80 tỷ sức mạnh để mở giới hạn tại đây",
                    "Đóng");
            return;
        }
        if (target.nPoint.limitPower >= NPoint.MAX_LIMIT) {
            this.createOtherMenu(owner, ConstNpc.IGNORE_MENU,
                    isPet ? "Sức mạnh của đệ tử đã đạt tới giới hạn"
                            : "Sức mạnh của con đã đạt tới giới hạn",
                    "Đóng");
            return;
        }

        if (isPet) {
            this.createOtherMenu(owner, ConstNpc.OPEN_POWER_PET,
                    "Ta sẽ truyền năng lượng giúp con mở giới hạn sức mạnh của đệ tử lên "
                            + Util.numberToMoney(target.nPoint.getPowerNextLimit()),
                    "Nâng ngay\n" + Util.numberToMoney(OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER) + " vàng",
                    "Đóng");
        } else {
            this.createOtherMenu(owner, ConstNpc.OPEN_POWER_MYSEFT,
                    "Ta sẽ truyền năng lượng giúp con mở giới hạn sức mạnh lên "
                            + Util.numberToMoney(target.nPoint.getPowerNextLimit()),
                    "Nâng\ngiới hạn\nsức mạnh",
                    "Nâng ngay\n" + Util.numberToMoney(OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER) + " vàng",
                    "Đóng");
        }
    }

    private void handleOpenPower(Player owner, Player target, int select, boolean isPet) {
        if (target.nPoint.limitPower < MIN_LIMIT_POWER
                || target.nPoint.limitPower >= NPoint.MAX_LIMIT) {
            Service.gI().sendThongBao(owner, "Không thể mở giới hạn sức mạnh tại Tổ sư Kaio");
            return;
        }
        switch (select) {
            case 0:
                if (!isPet) {
                    OpenPowerService.gI().openPowerBasic(target);
                    break;
                }
                if (owner.inventory.gold < OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER) {
                    Service.gI().sendThongBao(owner, "Bạn không đủ vàng để mở, còn thiếu "
                            + Util.numberToMoney(OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER - owner.inventory.gold)
                            + " vàng");
                    return;
                }
                if (OpenPowerService.gI().openPowerSpeed(target)) {
                    owner.inventory.gold -= OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER;
                    Service.gI().sendMoney(owner);
                }
                break;
            case 1:
                if (isPet) {
                    break;
                }
                if (owner.inventory.gold < OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER) {
                    Service.gI().sendThongBao(owner, "Bạn không đủ vàng để mở, còn thiếu "
                            + Util.numberToMoney(OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER - owner.inventory.gold)
                            + " vàng");
                    return;
                }
                if (OpenPowerService.gI().openPowerSpeed(target)) {
                    owner.inventory.gold -= OpenPowerService.COST_SPEED_OPEN_LIMIT_POWER;
                    Service.gI().sendMoney(owner);
                }
                break;
            default:
                break;
        }
    }

    private void handleAutoTrainingMenu(Player player) {
        if (player.dangKyTapTuDong) {
            player.dangKyTapTuDong = false;
            NpcService.gI().createTutorial(player, tempId, avartar, "Con đã hủy thành công đăng ký tập tự động\nTừ giờ con muốn tập Offline hãy tự đến đây trước");
        } else {
            showAutoTrainingRegistrationMenu(player);
        }
    }

    private void showAutoTrainingRegistrationMenu(Player player) {
        String message = String.format("Đăng ký để mỗi khi Offline quá 30 phút, con sẽ được tự động luyện tập với tốc độ %s sức mạnh mỗi phút",
                TrainingService.gI().getTnsmMoiPhut(player));
        this.createOtherMenu(player, 2001, message, "Hướng\ndẫn\nthêm", "Đồng ý\n1 ngọc\nmỗi lần", "Không\nđồng ý");
    }

    private void handleAutoTrainingRegistration(Player player, int select) {
        switch (select) {
            case 0:
                NpcService.gI().createTutorial(player, tempId, avartar, ConstNpc.TAP_TU_DONG);
                break;
            case 1:
                player.mapIdDangTapTuDong = mapId;
                player.dangKyTapTuDong = true;
                NpcService.gI().createTutorial(player, tempId, avartar, "Từ giờ, quá 30 phút Offline con sẽ được tự động luyện tập");
                break;
            default:
                break;
        }
    }
}
