import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

describe('test infrastructure smoke test', () => {
    it('mounts a Vue component in happy-dom', () => {
        const wrapper = mount({
            template: '<div class="smoke"><span>{{ message }}</span></div>',
            data() {
                return { message: 'ok' }
            },
        })

        expect(wrapper.find('.smoke span').text()).toBe('ok')
    })
})
