# Category Assignment Regression Checklist

- Assign AY category to one product from Products list drawer and save.
- Refresh Products page; verify `AY Category` column still shows the assigned ID.
- Re-import products from PrestaShop (`Import from PS`).
- Re-open same product; verify manual `ay_category_id` is unchanged.
- Select multiple products and run bulk AY category assignment.
- Verify only selected products changed and each keeps exactly one AY category ID.
- Run product sync for one updated product and confirm payload still sends one `category` value.
