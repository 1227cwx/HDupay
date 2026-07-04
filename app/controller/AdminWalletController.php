<?php

namespace app\controller;

use app\service\WalletService;
use app\service\WalletAssetService;
use support\Request;
use Throwable;

class AdminWalletController extends BaseController
{
    public function initialize(Request $request)
    {
        try {
            return $this->ok((new WalletService())->initialize($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function masters(Request $request)
    {
        try {
            return $this->ok((new WalletService())->masters());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function accounts(Request $request)
    {
        try {
            return $this->ok((new WalletService())->accounts());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function accountBalance(Request $request)
    {
        try {
            return $this->ok((new WalletService())->accountBalance($request->all()));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveCollectionTarget(Request $request)
    {
        try {
            return $this->ok((new WalletService())->saveCollectionTarget($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function createAccount(Request $request)
    {
        try {
            return $this->ok((new WalletService())->createAccount($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggleAccount(Request $request)
    {
        try {
            return $this->ok((new WalletService())->toggleAccount($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function overview(Request $request)
    {
        try {
            return $this->ok((new WalletService())->overview());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function exportRootPrivateKey(Request $request)
    {
        try {
            return $this->ok((new WalletService())->exportRootPrivateKey($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function deleteRootWallet(Request $request)
    {
        try {
            return $this->ok((new WalletService())->deleteRootWallet($this->input($request)), '根钱包已删除');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function updateAccount(Request $request)
    {
        try {
            return $this->ok((new WalletService())->updateAccount($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function collectionWallets(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->collectionWallets());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveCollectionWalletConfig(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->saveCollectionConfig($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function addCollectionAddress(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->addCollectionAddress($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggleCollectionAddress(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->setCollectionActive((int)$request->input('id', 0), (bool)$request->input('active', false)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggleCollectionAddressSync(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->setCollectionSyncEnabled((int)$request->input('id', 0), (bool)$request->input('sync_enabled', false)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function deleteCollectionAddress(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->deleteCollectionAddress((int)$request->input('id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function syncCollectionAddress(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->syncCollectionAddress((int)$request->input('id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function syncAllCollectionWallets(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->syncAllCollectionBalances());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function gasWallets(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->gasWallets());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function saveGasWalletConfig(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->saveGasConfig($this->input($request)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function toggleGasWalletSync(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->setGasSyncEnabled((int)$request->input('wallet_account_id', 0), (bool)$request->input('sync_enabled', false)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function syncGasWallet(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->syncGasWallet((int)$request->input('wallet_account_id', 0)));
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    public function syncAllGasWallets(Request $request)
    {
        try {
            return $this->ok((new WalletAssetService())->syncAllGasBalances());
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

}
