<div class="bootstrap" id="pricesync-dashboard">
    <div class="row">
        <div class="col-lg-12">
            <h2 class="page-title">
                <i class="icon-refresh"></i> Price Sync Manager
                <small class="text-muted">v1.0.0</small>
            </h2>
        </div>
    </div>

    <ul class="nav nav-tabs" role="tablist">
        <li class="active"><a href="#dashboard" role="tab" data-toggle="tab"><i class="icon-dashboard"></i> DASHBOARD</a></li>
        <li><a href="#config" role="tab" data-toggle="tab"><i class="icon-cogs"></i> KONFIGURÁCIÓ</a></li>
    </ul>

    <div class="tab-content">
        
        <div class="tab-pane active" id="dashboard">
            <div class="row">
                <div class="col-md-5">
                    <div class="panel">
                        <div class="panel-heading"><i class="icon-signal"></i> Kapcsolat Státusz</div>
                        
                        <div class="status-card">
                            <div class="status-info">
                                <strong>Beszállító API (Ez a Shop)</strong>
                                <span class="status-sub">Mód: {$psp_mode}</span>
                            </div>
                            <span class="badge badge-success">AKTÍV</span>
                        </div>

                        <div class="status-card">
                            <div class="status-info">
                                <strong>Shop 1 (RON)</strong>
                                <span class="status-sub">Utolsó szinkron: {$last_sync_date}</span>
                            </div>
                            {if $psp_s1_url}<span class="badge badge-success">Sync Ready</span>{else}<span class="badge badge-default">Nincs beállítva</span>{/if}
                        </div>

                        <div class="status-card">
                            <div class="status-info">
                                <strong>Shop 2 (HUF)</strong>
                                <span class="status-sub">Utolsó szinkron: {$last_sync_date}</span>
                            </div>
                            {if $psp_s2_url}<span class="badge badge-success">Sync Ready</span>{else}<span class="badge badge-default">Nincs beállítva</span>{/if}
                        </div>
                    </div>
                </div>

                <div class="col-md-7">
                    <div class="console-wrapper">
                        <div class="console-header">
                            <span class="dot red"></span>
                            <span class="dot yellow"></span>
                            <span class="dot green"></span>
                            <span class="console-title">LIVE SYNC CONSOLE</span>
                        </div>
                        <div class="console-body">
                            <div class="log-line"><span class="time">[{$last_sync_date}]</span> <span class="cmd">-> Rendszer indítása...</span> <span class="res success">SIKER!</span></div>
                            <div class="log-line"><span class="time">[{$last_sync_date}]</span> <span class="cmd">-> Shop 1 (RON) kapcsolat ellenőrzése...</span> <span class="res success">OK</span></div>
                            <div class="log-line"><span class="time">[{$last_sync_date}]</span> <span class="cmd">-> Shop 2 (HUF) kapcsolat ellenőrzése...</span> <span class="res success">OK</span></div>
                            <div class="log-line warning"><span class="time">[{$last_sync_date}]</span> [REAL-TIME] Várakozás árváltozásra...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane" id="config">
            <form action="{$action_url}" method="post">
                
                <div class="panel">
                    <div class="panel-heading">Alapbeállítások</div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Működési Mód</label>
                                <select name="PSP_MODE" class="form-control">
                                    <option value="OFF" {if $psp_mode=='OFF'}selected{/if}>Kikapcsolva</option>
                                    <option value="SENDER" {if $psp_mode=='SENDER'}selected{/if}>SENDER (Beszállító oldal)</option>
                                    <option value="RECEIVER" {if $psp_mode=='RECEIVER'}selected{/if}>RECEIVER (Fogadó oldal)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Titkos Token (API Kulcs)</label>
                                <input type="text" name="PSP_TOKEN" value="{$psp_token}" class="form-control" placeholder="Generálj egy erős jelszót ide...">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="panel panel-info">
                            <div class="panel-heading"><i class="icon-random"></i> 1. Shop (RON)</div>
                            <div class="panel-body">
                                <p class="help-block">Ez a bolt 1.5x szorzóval dolgozik.</p>
                                
                                <div class="form-group">
                                    <label>URL (Fogadó API)</label>
                                    <input type="text" name="PSP_S1_URL" value="{$psp_s1_url}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Ár Szorzó</label>
                                    <input type="text" name="PSP_S1_MULTIPLIER" value="{$psp_s1_multiplier}" class="form-control">
                                </div>
                                
                                <hr>
                                <h4><i class="icon-ban"></i> Tiltólista (Shop 1)</h4>
                                <div class="input-group">
                                    <input type="text" id="bl_input_1" class="form-control" placeholder="Cikkszám...">
                                    <span class="input-group-btn">
                                        <button class="btn btn-danger" type="button" onclick="addBlacklist(1)">Tiltás</button>
                                    </span>
                                </div>
                                <br>
                                <table class="table table-black-header">
                                    <thead><tr><th>Kép</th><th>Név</th><th>Ref</th><th></th></tr></thead>
                                    <tbody>
                                        {foreach from=$blacklist_s1 item=b}
                                        <tr>
                                            <td>{if $b.image_url}<img src="{$b.image_url}" width="30">{/if}</td>
                                            <td>{$b.product_name}</td>
                                            <td>{$b.reference}</td>
                                            <td><a href="{$action_url}&deleteblacklist=1&id_blacklist={$b.id_blacklist}" class="btn btn-xs btn-default"><i class="icon-trash"></i></a></td>
                                        </tr>
                                        {foreachelse}
                                        <tr><td colspan="4" class="text-center text-muted">Nincs tiltott termék.</td></tr>
                                        {/foreach}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="panel panel-info">
                            <div class="panel-heading"><i class="icon-random"></i> 2. Shop (HUF)</div>
                            <div class="panel-body">
                                <p class="help-block">Ez a bolt 85x szorzóval és kerekítéssel dolgozik.</p>

                                <div class="form-group">
                                    <label>URL (Fogadó API)</label>
                                    <input type="text" name="PSP_S2_URL" value="{$psp_s2_url}" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Ár Szorzó</label>
                                    <input type="text" name="PSP_S2_MULTIPLIER" value="{$psp_s2_multiplier}" class="form-control">
                                </div>

                                <hr>
                                <h4><i class="icon-ban"></i> Tiltólista (Shop 2)</h4>
                                <div class="input-group">
                                    <input type="text" id="bl_input_2" class="form-control" placeholder="Cikkszám...">
                                    <span class="input-group-btn">
                                        <button class="btn btn-danger" type="button" onclick="addBlacklist(2)">Tiltás</button>
                                    </span>
                                </div>
                                <br>
                                <table class="table table-black-header">
                                    <thead><tr><th>Kép</th><th>Név</th><th>Ref</th><th></th></tr></thead>
                                    <tbody>
                                        {foreach from=$blacklist_s2 item=b}
                                        <tr>
                                            <td>{if $b.image_url}<img src="{$b.image_url}" width="30">{/if}</td>
                                            <td>{$b.product_name}</td>
                                            <td>{$b.reference}</td>
                                            <td><a href="{$action_url}&deleteblacklist=1&id_blacklist={$b.id_blacklist}" class="btn btn-xs btn-default"><i class="icon-trash"></i></a></td>
                                        </tr>
                                        {foreachelse}
                                        <tr><td colspan="4" class="text-center text-muted">Nincs tiltott termék.</td></tr>
                                        {/foreach}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel-footer">
                    <button type="submit" name="submitPriceSyncConfig" class="btn btn-default pull-right"><i class="process-icon-save"></i> MENTÉS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="blacklist_form" action="{$action_url}" method="post" style="display:none;">
    <input type="hidden" name="submitBlacklistAdd" value="1">
    <input type="hidden" name="blacklist_ref" id="hidden_bl_ref">
    <input type="hidden" name="blacklist_shop_target" id="hidden_bl_target">
</form>

<script>
function addBlacklist(shopId) {
    var ref = $('#bl_input_' + shopId).val();
    if(ref) {
        $('#hidden_bl_ref').val(ref);
        $('#hidden_bl_target').val(shopId);
        $('#blacklist_form').submit();
    } else {
        alert('Kérlek írj be egy cikkszámot!');
    }
}
</script>
