import assert from 'node:assert/strict'
import test from 'node:test'
import {
    accountName, actionName, availableAction, claimStage, claimStatus, claimType,
    expectedResolution, historyAction, playerRole,
} from '../../resources/js/lib/meliClaimsPresentation.js'

test('traduce los valores operativos sin cambiar sus códigos internos', () => {
    assert.equal(claimStatus('opened'), 'Abierto')
    assert.equal(claimStatus('closed'), 'Cerrado')
    assert.equal(claimStage('claim'), 'Reclamo')
    assert.equal(claimStage('dispute'), 'Mediación / disputa')
    assert.equal(claimType('mediations'), 'Mediación')
    assert.equal(playerRole('respondent'), 'Vendedor')
    assert.equal(availableAction('allow_partial_refund'), 'Ofrecer reembolso parcial')
    assert.equal(expectedResolution('return_product'), 'Devolver producto')
})

test('usa action_name real, fallbacks legibles y nombres de cuenta', () => {
    assert.equal(actionName({ action_name: 'open_claim', action: 'ignored' }), 'open_claim')
    assert.equal(historyAction('open_claim'), 'Reclamo abierto')
    assert.equal(historyAction('unknown_action'), 'Unknown Action')
    assert.equal(accountName({ is_default: true, meli_user_id: '123', nickname: null }), 'Cuenta principal · 123')
    assert.equal(accountName({ is_default: false, meli_user_id: '456', nickname: null }), 'Cuenta secundaria · 456')
    assert.equal(accountName({ is_default: true, meli_user_id: '123', nickname: 'Mi cuenta' }), 'Mi cuenta')
})
