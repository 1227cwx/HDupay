<?php

namespace app\model;

class MonitorCursor extends BaseModel
{
    protected $table = 'monitor_cursors';
    protected $primaryKey = 'id';

    public static function getOrCreate(string $networkCode, string $tokenCode, string $contractAddress, int $confirmBlocks, int $scanStepBlocks): array
    {
        $row = self::query()->where('network_code', $networkCode)->where('token_code', $tokenCode)->first();
        if ($row) {
            $current = $row->toArray();
            $data = [];
            if (strtolower((string)($current['contract_address'] ?? '')) !== strtolower($contractAddress)) {
                $data['contract_address'] = strtolower($contractAddress);
            }
            if ((int)($current['confirm_blocks'] ?? 0) !== $confirmBlocks) {
                $data['confirm_blocks'] = $confirmBlocks;
            }
            if ((int)($current['scan_step_blocks'] ?? 0) !== $scanStepBlocks) {
                $data['scan_step_blocks'] = $scanStepBlocks;
            }
            if ($data) {
                self::updateById((int)$current['id'], $data);
                return self::findById((int)$current['id']) ?? $current;
            }
            return $current;
        }
        return self::createRecord([
            'network_code' => $networkCode,
            'token_code' => $tokenCode,
            'contract_address' => strtolower($contractAddress),
            'last_scanned_block' => 0,
            'confirm_blocks' => $confirmBlocks,
            'scan_step_blocks' => $scanStepBlocks,
            'status' => 'enabled',
        ]);
    }

    public static function updateBlock(int $id, int $blockNumber): bool
    {
        return self::updateById($id, ['last_scanned_block' => $blockNumber]);
    }
}
